<?php

namespace App\Http\Controllers;

use App\Models\PayrollCfdi;
use App\Models\PayrollEntry;
use App\Models\PayrollPeriod;
use App\Services\Cfdi\PayrollCfdiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Timbrado CFDI de nómina: disparar/cancelar el timbrado de un periodo y
 * descargar XML/PDF por empleado. Permiso payroll.approve (misma llave que
 * aprobar: timbrar es un acto fiscal).
 */
class PayrollCfdiController extends Controller
{
    public function __construct(private PayrollCfdiService $cfdi) {}

    /**
     * Dispara el timbrado del periodo (jobs por empleado).
     */
    public function stamp(PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.approve')) {
            abort(403);
        }

        if (! in_array($payroll->status, ['approved', 'paid'], true)) {
            return redirect()->back()->with('error', 'Solo se timbran nóminas aprobadas.');
        }

        $result = $this->cfdi->stampPeriod($payroll);

        $msg = "Timbrado en cola: {$result['queued']} recibo(s); ya timbrados: {$result['skipped']}.";
        if (! empty($result['invalid'])) {
            $msg .= ' Con datos faltantes: '.count($result['invalid']).' empleado(s) (CURP/RFC/CP — ver detalle).';
        }

        return redirect()->route('payroll.show', $payroll)->with(
            empty($result['invalid']) ? 'success' : 'warning',
            $msg,
        );
    }

    /**
     * Cancela ante el SAT los CFDI del periodo (para poder recalcular).
     */
    public function cancel(PayrollPeriod $payroll): RedirectResponse
    {
        if (! auth()->user()->hasPermissionTo('payroll.approve')) {
            abort(403);
        }

        $canceled = $this->cfdi->cancelPeriod($payroll);

        return redirect()->route('payroll.show', $payroll)
            ->with('success', "Se cancelaron {$canceled} CFDI(s). Ya puedes recalcular y re-timbrar.");
    }

    /**
     * Descarga el XML timbrado de un entry.
     */
    public function downloadXml(PayrollEntry $entry): StreamedResponse
    {
        return $this->download($entry, 'xml_path', 'xml');
    }

    /**
     * Descarga el PDF del recibo timbrado de un entry.
     */
    public function downloadPdf(PayrollEntry $entry): StreamedResponse
    {
        return $this->download($entry, 'pdf_path', 'pdf');
    }

    private function download(PayrollEntry $entry, string $column, string $ext): StreamedResponse
    {
        $user = auth()->user();
        if (! $user->hasPermissionTo('payroll.view_complete')) {
            abort(403);
        }

        $cfdi = $entry->cfdis()
            ->where('status', PayrollCfdi::STATUS_STAMPED)
            ->latest('stamped_at')
            ->first();

        $path = $cfdi?->{$column};
        if (! $cfdi || ! $path || ! Storage::exists($path)) {
            abort(404, 'CFDI no disponible.');
        }

        $name = sprintf('cfdi-%s-%s.%s', $entry->employee?->employee_number ?? $entry->id, $cfdi->uuid ?? 'sin-uuid', $ext);

        return Storage::download($path, $name);
    }
}
