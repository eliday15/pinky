<?php

namespace App\Services\Payroll;

use App\Models\PayrollEntry;

/**
 * Renglones de un recibo para la PRENÓMINA: qué cobra la persona y por qué.
 *
 * Mismo desglose que el detalle del recibo (EntryDetail.vue): sueldo, faltas,
 * extras, cada concepto con su cantidad, retenciones. El DETALLE nunca lleva
 * dinero (solo días, horas o unidades) y el importe va aparte, para que quien
 * no ve montos reciba la misma lista sin números. Los renglones que solo son
 * dinero (ISR, IMSS, ajustes) van marcados money_only.
 */
class PrenominaLineBuilder
{
    /** Cuadre por debajo de un centavo es redondeo. */
    private const EPSILON = 0.01;

    /**
     * @return array{lines: list<array{label: string, detail: string, amount: float, money_only?: bool}>, net: float, gross: float, cash: float, bank: float, alerts: list<string>}
     */
    public function forEntry(PayrollEntry $entry, string $pass): array
    {
        $b = $entry->calculation_breakdown ?? [];
        $lines = [];
        $add = function (string $label, string $detail, float $amount, bool $moneyOnly = false) use (&$lines) {
            if (abs($amount) < 0.005) {
                return;
            }
            $lines[] = ['label' => $label, 'detail' => $detail, 'amount' => round($amount, 2)] + ($moneyOnly ? ['money_only' => true] : []);
        };

        $baseDays = (float) ($b['base']['base_paid_days'] ?? 0);
        $baseDetail = $baseDays > 0 ? $this->num($baseDays).' días' : '';
        if (! empty($b['base']['starts_on'])) {
            $baseDetail .= ' — entró el '.$b['base']['starts_on'];
        }
        if (! empty($b['base']['ends_on'])) {
            $baseDetail .= ' — baja el '.$b['base']['ends_on'];
        }
        $add('Sueldo base', $baseDetail, (float) $entry->regular_pay);

        $absenceDays = (float) ($b['incidents']['absence_deduction_days'] ?? 0);
        $add('Faltas (descuento)', $absenceDays > 0 ? $this->num($absenceDays).' día(s)' : '', -(float) $entry->deductions);

        $add('Horas extra', $this->qty((float) $entry->overtime_authorized_hours, 'h'), (float) $entry->overtime_pay);
        $add('Días festivos', '', (float) $entry->holiday_pay);
        $add('Velada', $this->qty((float) $entry->velada_days, 'noche(s)'), (float) $entry->velada_pay);
        $add('Fin de semana', $this->qty((float) ($b['weekend']['units'] ?? 0), 'fin(es)'), (float) $entry->weekend_pay);

        foreach ($b['compensation_concepts'] ?? [] as $c) {
            if (! empty($c['informational']) || ! $this->isOtherConcept($c)) {
                continue;
            }
            // Un monto negativo es un descuento (Infonavit, préstamo) y resta.
            $amount = (float) ($c['amount'] ?? 0);
            $add((string) ($c['name'] ?? $c['code'] ?? 'Concepto'), $this->conceptDetail($c), $amount);
        }

        $add('Vacaciones', $this->qty((float) $entry->vacation_days_paid, 'día(s)'), (float) $entry->vacation_pay);
        $add('Prima vacacional', '', (float) $entry->vacation_premium_pay);
        $add('Incapacidad', $this->qty((float) ($b['incidents']['sick_leave_paid_days'] ?? 0), 'día(s)'), (float) $entry->sick_leave_pay);
        $add('Bonos', '', (float) $entry->bonuses);
        $add('ISR (retención)', '', -(float) $entry->isr_amount, true);
        $add('IMSS (retención)', '', -(float) $entry->imss_amount, true);
        $add('Infonavit (crédito)', '', -(float) $entry->infonavit_amount, true);
        $add('Subsidio al empleo', '', (float) $entry->subsidy_amount, true);
        $add('Ajuste al neto', 'redondeo', (float) $entry->net_adjustment, true);

        // Lo que pagan las retenciones es el neto a recibir (banco + efectivo);
        // si los renglones no cuadran con ese total, se enseña la diferencia en
        // vez de esconderla (un recorte que no se explica es un bug).
        $toReceive = round((float) $entry->cash_amount + (float) $entry->bank_amount, 2);
        $shown = round(array_sum(array_column($lines, 'amount')), 2);
        if (abs($toReceive - $shown) >= self::EPSILON) {
            $add('Sin desglosar (revisar)', '', $toReceive - $shown, true);
        }

        $alerts = [];
        foreach ($b['duplicate_concepts'] ?? [] as $d) {
            $alerts[] = ($d['name'] ?? 'Concepto').' del '.($d['date'] ?? '?').' capturado dos veces: la repetida (#'.($d['authorization_id'] ?? '?').') no se paga.';
        }
        foreach ($b['unpaid_zero_amount_concepts'] ?? [] as $z) {
            $alerts[] = ($z['name'] ?? 'Concepto').' capturado pero NO pagado: el concepto no tiene monto configurado.';
        }

        return [
            'lines' => $lines,
            'net' => $toReceive,
            'gross' => round((float) $entry->gross_pay, 2),
            'cash' => round((float) $entry->cash_amount, 2),
            'bank' => round((float) $entry->bank_amount, 2),
            'alerts' => $alerts,
        ];
    }

    /**
     * Mismo ruteo que el recibo: TE, velada, festivo y fin de semana tienen su
     * propio renglón; lo demás (bonos, comida, cena, recurrentes) es concepto.
     */
    private function isOtherConcept(array $c): bool
    {
        if (($c['source'] ?? null) === 'recurring') {
            return true;
        }
        $code = (string) ($c['code'] ?? '');
        $authType = $c['authorization_type'] ?? null;

        return ! in_array($code, ['HE', 'HED', 'HET', 'VEL'], true)
            && $authType !== 'night_shift'
            && $authType !== 'holiday_worked'
            && ($c['attendance_pull_rule'] ?? null) !== 'weekend';
    }

    /** "600 unidades", "3 h", "2 día(s)" — nunca el precio. */
    private function conceptDetail(array $c): string
    {
        $qty = abs((float) ($c['quantity'] ?? 0));
        if ($qty > 1) {
            return $this->num($qty).' unidades';
        }
        if ((float) ($c['hours'] ?? 0) > 0) {
            return $this->num((float) $c['hours']).' h';
        }
        if ((float) ($c['days'] ?? 0) > 0) {
            return $this->num((float) $c['days']).' día(s)';
        }

        return ($c['source'] ?? null) === 'recurring' ? 'fijo cada periodo' : '';
    }

    private function qty(float $value, string $unit): string
    {
        return $value > 0 ? $this->num($value).' '.$unit : '';
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
