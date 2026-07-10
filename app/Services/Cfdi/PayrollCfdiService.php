<?php

namespace App\Services\Cfdi;

use App\Jobs\StampPayrollCfdi;
use App\Models\PayrollCfdi;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\Storage;

/**
 * Orquesta el timbrado CFDI de un periodo: valida datos, despacha jobs por
 * empleado (idempotentes), guarda XML/PDF y maneja cancelación para permitir
 * recálculos. El candado de recálculo vive en PayrollController::calculate.
 */
class PayrollCfdiService
{
    public function __construct(
        private PayrollCfdiBuilder $builder,
        private PacProviderInterface $pac,
    ) {}

    /**
     * Entries del periodo que se timbran: formalizados con transferencia > 0.
     */
    public function stampableEntries(PayrollPeriod $period)
    {
        return PayrollEntry::where('payroll_period_id', $period->id)
            ->where('bank_amount', '>', 0)
            ->with(['employee.department', 'employee.position', 'payrollPeriod'])
            ->get()
            ->filter(fn (PayrollEntry $e) => $e->employee && ! $e->employee->paysBaseInCash());
    }

    /**
     * Valida el periodo y despacha un job de timbrado por entry.
     *
     * Returns:
     *     ['queued' => n, 'skipped' => n (ya timbrados), 'invalid' => [entry_id => campos]]
     */
    public function stampPeriod(PayrollPeriod $period): array
    {
        $queued = 0;
        $skipped = 0;
        $invalid = [];

        foreach ($this->stampableEntries($period) as $entry) {
            if ($entry->cfdis()->active()->exists()) {
                $skipped++;

                continue;
            }

            $missing = $this->builder->missingFields($entry);
            if (! empty($missing)) {
                $invalid[$entry->id] = $missing;

                continue;
            }

            StampPayrollCfdi::dispatch($entry->id);
            $queued++;
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'invalid' => $invalid];
    }

    /**
     * Timbra UN entry (idempotente: si ya tiene CFDI activo no re-timbra).
     * La llama el job; los errores del PAC quedan en el registro (status
     * error) para re-intentar desde la UI.
     */
    public function stampEntry(PayrollEntry $entry): PayrollCfdi
    {
        $existing = $entry->cfdis()->active()->first();
        if ($existing && $existing->status === PayrollCfdi::STATUS_STAMPED) {
            return $existing;
        }

        $cfdi = $existing ?: $entry->cfdis()->create(['status' => PayrollCfdi::STATUS_PENDING]);
        $cfdi->increment('attempts');

        try {
            $payload = $this->builder->build($entry);
            $result = $this->pac->stamp($payload);

            $period = $entry->payrollPeriod;
            $dir = sprintf('cfdis/%s/%d', $period->start_date->format('Y'), $period->id);
            $xmlPath = "{$dir}/entry-{$entry->id}.xml";
            Storage::put($xmlPath, $result['xml'] ?: '');

            $pdfPath = null;
            try {
                $pdf = $this->pac->getPdf($result['pac_id']);
                if ($pdf !== '') {
                    $pdfPath = "{$dir}/entry-{$entry->id}.pdf";
                    Storage::put($pdfPath, $pdf);
                }
            } catch (\Throwable $e) {
                // El PDF es cosmético (el XML es el CFDI legal); no truena el timbrado.
                report($e);
            }

            $cfdi->update([
                'status' => PayrollCfdi::STATUS_STAMPED,
                'uuid' => $result['uuid'] ?: null,
                'pac_id' => $result['pac_id'] ?: null,
                'xml_path' => $xmlPath,
                'pdf_path' => $pdfPath,
                'pac_response' => null,
                'stamped_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $cfdi->update([
                'status' => PayrollCfdi::STATUS_ERROR,
                'pac_response' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        return $cfdi->fresh();
    }

    /**
     * Cancela ante el SAT todos los CFDI timbrados del periodo (previo a un
     * recálculo). Motivo 02 = comprobante con errores sin relación.
     */
    public function cancelPeriod(PayrollPeriod $period, string $motive = '02'): int
    {
        $canceled = 0;
        $cfdis = PayrollCfdi::whereHas('payrollEntry', fn ($q) => $q->where('payroll_period_id', $period->id))
            ->where('status', PayrollCfdi::STATUS_STAMPED)
            ->get();

        foreach ($cfdis as $cfdi) {
            if ($cfdi->pac_id) {
                $this->pac->cancel($cfdi->pac_id, $motive);
            }
            $cfdi->update([
                'status' => PayrollCfdi::STATUS_CANCELED,
                'canceled_at' => now(),
            ]);
            $canceled++;
        }

        // Los pendientes/con error se marcan cancelados también (ya no aplican).
        PayrollCfdi::whereHas('payrollEntry', fn ($q) => $q->where('payroll_period_id', $period->id))
            ->whereIn('status', [PayrollCfdi::STATUS_PENDING, PayrollCfdi::STATUS_ERROR])
            ->update(['status' => PayrollCfdi::STATUS_CANCELED, 'canceled_at' => now()]);

        return $canceled;
    }

    /**
     * Resumen del timbrado del periodo para la UI.
     */
    public function periodStatus(PayrollPeriod $period): array
    {
        $counts = PayrollCfdi::whereHas('payrollEntry', fn ($q) => $q->where('payroll_period_id', $period->id))
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return [
            'stamped' => (int) ($counts[PayrollCfdi::STATUS_STAMPED] ?? 0),
            'pending' => (int) ($counts[PayrollCfdi::STATUS_PENDING] ?? 0),
            'error' => (int) ($counts[PayrollCfdi::STATUS_ERROR] ?? 0),
            'canceled' => (int) ($counts[PayrollCfdi::STATUS_CANCELED] ?? 0),
            'stampable' => $this->stampableEntries($period)->count(),
        ];
    }
}
