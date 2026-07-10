<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Fiscal\ImssCalculatorService;
use App\Services\Fiscal\InfonavitCalculatorService;
use App\Services\Fiscal\IsrCalculatorService;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

/**
 * Concilia las calculadoras fiscales de Pinky contra los montos reales de la
 * Lista de Raya de Contpaq (JSON parseado del PDF). Reporta, por retención, el
 * conteo de coincidencias y las sumas — la prueba de aceptación viva.
 *
 * JSON esperado por empleado: {code, sueldo, septimo, vacaciones, cumple,
 * prima_vac, aguinaldo, sbc, sdi, dias, imss, isr_mes, infonavit_cf, infonavit_fd}.
 */
class FiscalReconcileContpaq extends Command
{
    protected $signature = 'fiscal:reconcile-contpaq {json : JSON parseado del PDF} {--tol-isr=1.0} {--tol-imss=0.6} {--patronal : Suma también las cuotas patronales calculadas (comparar vs el bloque Obligaciones del PDF)}';

    protected $description = 'Concilia ISR/IMSS/Infonavit de Pinky vs los montos de Contpaq';

    public function handle(
        ImssCalculatorService $imssCalc,
        IsrCalculatorService $isrCalc,
        InfonavitCalculatorService $infCalc,
    ): int {
        $path = $this->argument('json');
        if (! is_file($path)) {
            $this->error("No existe: {$path}");

            return self::FAILURE;
        }

        $uma = (float) SystemSetting::get('fiscal_uma_daily', 117.31);
        $tolIsr = (float) $this->option('tol-isr');
        $tolImss = (float) $this->option('tol-imss');

        $rows = collect(json_decode(file_get_contents($path), true))
            ->reject(fn ($r) => ($r['code'] ?? '') === 'ZEYD-901');

        $total = $okIsr = $okImss = $okInf = 0;
        $sumIsrP = $sumIsrC = $sumImssP = $sumImssC = $sumInfP = $sumInfC = 0.0;
        $worst = [];

        foreach ($rows as $r) {
            $emp = Employee::where('contpaqi_code', $r['code'] ?? null)->first();
            if (! $emp) {
                continue;
            }
            $total++;
            $sal = (float) $emp->daily_salary_computed;
            $sbc = (float) ($emp->sbc ?: ($r['sbc'] ?? 0));
            $sdi = (float) ($emp->sdi ?: ($r['sdi'] ?? 0));
            $dias = (float) ($r['dias'] ?? 7);

            // Gravable = componentes gravados (sueldo + séptimo + vacaciones +
            // cumpleaños) + excedentes de prima (15 UMA) y aguinaldo (30 UMA).
            // USAR EL JSON FULL (contpaq_full_sem28.json): el parse viejo no
            // desglosaba vacaciones de algunos empleados y subestimaba.
            $grav = ($r['sueldo'] ?? 0) + ($r['septimo'] ?? 0) + ($r['vacaciones'] ?? 0) + ($r['cumple'] ?? 0)
                + max(0, ($r['prima_vac'] ?? 0) - 15 * $uma) + max(0, ($r['aguinaldo'] ?? 0) - 30 * $uma);

            // Faltas enteras inferidas de los días de Contpaq (días pagados =
            // 7 − faltas×7/6 → faltas = (7−dias)×6/7). El IMSS cotiza los 7
            // días en EyM y descuenta las faltas solo de IV+CyV (Art. 31 LSS).
            $faltas = (int) round((7.0 - $dias) * 6.0 / 7.0);

            $isr = $isrCalc->calculate($grav, $sal, 'weekly', 7.0)['isr'];
            $imss = $imssCalc->workerQuota($sbc, 7.0, $sal, $faltas);
            $inf = $infCalc->deduction($emp, $sdi, $dias, 7.0);
            $cInf = ($r['infonavit_cf'] ?? 0) + ($r['infonavit_fd'] ?? 0);

            if (abs($isr - ($r['isr_mes'] ?? 0)) <= $tolIsr) $okIsr++;
            if (abs($imss - ($r['imss'] ?? 0)) <= $tolImss) $okImss++;
            if (abs($inf - $cInf) <= 1.0) $okInf++;

            $sumIsrP += $isr; $sumIsrC += $r['isr_mes'] ?? 0;
            $sumImssP += $imss; $sumImssC += $r['imss'] ?? 0;
            $sumInfP += $inf; $sumInfC += $cInf;

            $worst[] = [abs($isr - ($r['isr_mes'] ?? 0)) + abs($imss - ($r['imss'] ?? 0)), $r['code'], $isr, $r['isr_mes'] ?? 0, $imss, $r['imss'] ?? 0];
        }

        $this->info("Empleados conciliados: {$total}");
        $this->line(sprintf('ISR       %d/%d al ±%.2f  | suma Pinky %s vs Contpaq %s', $okIsr, $total, $tolIsr, number_format($sumIsrP, 2), number_format($sumIsrC, 2)));
        $this->line(sprintf('IMSS      %d/%d al ±%.2f  | suma Pinky %s vs Contpaq %s', $okImss, $total, $tolImss, number_format($sumImssP, 2), number_format($sumImssC, 2)));
        $this->line(sprintf('Infonavit %d/%d al ±1.00 | suma Pinky %s vs Contpaq %s', $okInf, $total, number_format($sumInfP, 2), number_format($sumInfC, 2)));

        usort($worst, fn ($a, $b) => $b[0] <=> $a[0]);
        $this->line('Peores 6 (code | isr P/C | imss P/C):');
        foreach (array_slice($worst, 0, 6) as $w) {
            $this->line(sprintf('  %-10s isr %.2f/%.2f  imss %.2f/%.2f', $w[1], $w[2], $w[3], $w[4], $w[5]));
        }

        // ---- Cuotas PATRONALES (opcional): sumar el cálculo de Pinky por
        // empleado para comparar contra el bloque Obligaciones del PDF ----
        if ($this->option('patronal')) {
            $employer = app(\App\Services\Fiscal\EmployerQuotaCalculatorService::class);
            $sums = [];
            foreach ($rows as $r) {
                $sbc = (float) ($r['sbc'] ?? 0);
                if ($sbc <= 0) {
                    continue;
                }
                $dias = (float) ($r['dias'] ?? 7);
                $faltas = (int) round((7.0 - $dias) * 6.0 / 7.0);
                $q = $employer->quotas($sbc, 7.0, $faltas, (float) ($r['sal_diario'] ?? 0), (float) ($r['percepciones'] ?? 0));
                foreach ($q as $rubro => $amount) {
                    $sums[$rubro] = round(($sums[$rubro] ?? 0) + $amount, 2);
                }
            }
            $this->newLine();
            $this->info('Cuotas patronales calculadas (comparar vs bloque Obligaciones del PDF):');
            foreach ($sums as $rubro => $amount) {
                $this->line(sprintf('  %-18s %12s', $rubro, number_format($amount, 2)));
            }
            $imssEmpresa = ($sums['eym_fixed'] ?? 0) + ($sums['eym_excess'] ?? 0) + ($sums['eym_money_gmp'] ?? 0)
                + ($sums['iv'] ?? 0) + ($sums['cyv'] ?? 0) + ($sums['absorbed_worker'] ?? 0);
            $this->line(sprintf('  %-18s %12s  (rubros IMSS sin guardería/SAR — vs "96 IMSS empresa")', 'imss_empresa', number_format($imssEmpresa, 2)));
        }

        return self::SUCCESS;
    }
}
