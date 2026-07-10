<?php

namespace App\Jobs;

use App\Models\PayrollEntry;
use App\Services\Cfdi\PayrollCfdiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Timbra el CFDI de nómina de UN PayrollEntry ante el PAC. Idempotente (el
 * servicio no re-timbra si ya hay CFDI activo); reintenta con backoff ante
 * errores transitorios del PAC. El error final queda en payroll_cfdis
 * (status=error) re-timbrable desde la UI.
 */
class StampPayrollCfdi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> Backoff en segundos entre reintentos. */
    public array $backoff = [30, 120];

    public function __construct(public int $payrollEntryId) {}

    public function handle(PayrollCfdiService $service): void
    {
        $entry = PayrollEntry::with(['employee.department', 'employee.position', 'payrollPeriod'])
            ->find($this->payrollEntryId);

        if (! $entry) {
            return;
        }

        $service->stampEntry($entry);
    }
}
