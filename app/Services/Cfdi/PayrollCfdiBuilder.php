<?php

namespace App\Services\Cfdi;

use App\Models\CompensationType;
use App\Models\PayrollEntry;
use App\Models\SystemSetting;

/**
 * Arma el JSON del CFDI 4.0 de nómina (complemento 1.2, formato de alto nivel
 * de Facturama) desde un PayrollEntry. El CFDI ampara lo que se paga por
 * NÓMINA FORMAL (transferencia): sueldo base + percepciones por transferencia
 * (prima, cumpleaños, aguinaldo) − retenciones (ISR/IMSS/Infonavit) + subsidio
 * + ajuste al neto. Los extras en efectivo no forman parte del recibo (mismo
 * alcance que la conciliación vs Contpaq).
 *
 * Los nombres de campo siguen la guía de Facturama; cualquier ajuste fino se
 * hace aquí (único punto de mapeo) al probar en sandbox.
 */
class PayrollCfdiBuilder
{
    /**
     * Valida que el empleado tenga los datos que exige el CFDI.
     *
     * Returns:
     *     Lista de campos faltantes (vacía si está completo).
     */
    public function missingFields(PayrollEntry $entry): array
    {
        $e = $entry->employee;
        $missing = [];

        foreach ([
            'rfc' => $e->rfc,
            'curp' => $e->curp,
            'imss_number' => $e->imss_number,
            'address_zip' => $e->address_zip,
            'hire_date' => $e->hire_date,
            'daily_salary' => (float) $e->daily_salary_computed > 0 ? 'ok' : null,
            'sdi' => (float) $e->sdi > 0 ? 'ok' : null,
        ] as $field => $value) {
            if (blank($value)) {
                $missing[] = $field;
            }
        }

        foreach ([
            'company_legal_name' => SystemSetting::get('company_legal_name'),
            'company_rfc' => SystemSetting::get('company_rfc'),
            'company_employer_registration' => SystemSetting::get('company_employer_registration'),
            'company_zip_code' => SystemSetting::get('company_zip_code'),
        ] as $field => $value) {
            if (blank($value)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Construye el payload del CFDI de nómina para el PAC.
     */
    public function build(PayrollEntry $entry): array
    {
        $e = $entry->employee;
        $period = $entry->payrollPeriod;
        $breakdown = $entry->calculation_breakdown ?? [];
        $fiscal = $breakdown['fiscal'] ?? [];
        $uma = (float) SystemSetting::get('fiscal_uma_daily', 117.31);

        // ---- Percepciones (gravado/exento por tipo) ----
        $perceptions = [];
        $sueldo = round((float) $entry->regular_pay, 2);
        if ($sueldo > 0) {
            $perceptions[] = $this->perception('001', 'Sueldo', $sueldo, 0.0);
        }

        $prima = round((float) $entry->vacation_premium_pay, 2);
        if ($prima > 0) {
            $exempt = min($prima, 15 * $uma);
            $perceptions[] = $this->perception('021', 'Prima vacacional', round($prima - $exempt, 2), $exempt);
        }

        // Conceptos por transferencia del breakdown (cumpleaños, aguinaldo...).
        $satCodes = null;
        foreach (($breakdown['compensation_concepts'] ?? []) as $concept) {
            if (empty($concept['via_transfer'])) {
                continue;
            }
            $amount = round((float) ($concept['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $code = (string) ($concept['code'] ?? '');
            if ($code === 'AGUIN') {
                $exempt = min($amount, 30 * $uma);
                $perceptions[] = $this->perception('002', 'Aguinaldo', round($amount - $exempt, 2), $exempt);

                continue;
            }
            // Clave SAT del concepto (catálogo c_TipoPercepcion); 038 "Otros
            // ingresos por salarios" como fallback. CUMPLE → 029 premios si no
            // se configura otra cosa.
            $satCodes ??= CompensationType::whereNotNull('sat_perception_code')->pluck('sat_perception_code', 'code');
            $sat = (string) ($satCodes[$code] ?? ($code === 'CUMPLE' ? '029' : '038'));
            $perceptions[] = $this->perception($sat, (string) ($concept['name'] ?? $code), $amount, 0.0);
        }

        // ---- Deducciones ----
        $deductions = [];
        if ((float) $entry->deductions > 0) {
            // Faltas: el CFDI las declara como deducción 020 "Ausencias".
            $deductions[] = $this->deduction('020', 'Faltas', round((float) $entry->deductions, 2));
        }
        if ((float) $entry->isr_amount > 0) {
            $deductions[] = $this->deduction('002', 'ISR', round((float) $entry->isr_amount, 2));
        }
        if ((float) $entry->imss_amount > 0) {
            $deductions[] = $this->deduction('001', 'Seguridad social (IMSS)', round((float) $entry->imss_amount, 2));
        }
        if ((float) $entry->infonavit_amount > 0) {
            $deductions[] = $this->deduction('010', 'Pago por crédito de vivienda (Infonavit)', round((float) $entry->infonavit_amount, 2));
        }
        $netAdjustment = round((float) ($entry->net_adjustment ?? 0), 2);
        if ($netAdjustment < 0) {
            $deductions[] = $this->deduction('004', 'Ajuste al neto', abs($netAdjustment));
        }

        // ---- Otros pagos: subsidio al empleo acreditado + ajuste positivo ----
        $otherPayments = [];
        $subsidy = round((float) $entry->subsidy_amount, 2);
        if ($subsidy > 0) {
            $otherPayments[] = [
                'OtherPaymentType' => '002',
                'Code' => '002',
                'Description' => 'Subsidio para el empleo',
                'Amount' => $subsidy,
                'EmploymentSubsidy' => ['Amount' => $subsidy],
            ];
        }
        if ($netAdjustment > 0) {
            $otherPayments[] = [
                'OtherPaymentType' => '999',
                'Code' => '999',
                'Description' => 'Ajuste al neto',
                'Amount' => $netAdjustment,
            ];
        }

        $daysPaid = (float) (data_get($breakdown, 'scope.week_days') ?: 7);

        return [
            'CfdiType' => 'N',
            'NameId' => '1',
            'ExpeditionPlace' => (string) SystemSetting::get('company_zip_code', ''),
            'PaymentForm' => '99',   // por definición en nómina
            'PaymentMethod' => 'PUE',
            'Issuer' => [
                'Rfc' => (string) SystemSetting::get('company_rfc', ''),
                'Name' => (string) SystemSetting::get('company_legal_name', ''),
                'FiscalRegime' => (string) SystemSetting::get('company_fiscal_regime', '601'),
            ],
            'Receiver' => [
                'Rfc' => (string) $e->rfc,
                'Name' => mb_strtoupper((string) $e->full_name),
                'CfdiUse' => 'CN01', // nómina
                'FiscalRegime' => (string) ($e->fiscal_regime ?: '605'),
                'TaxZipCode' => (string) $e->address_zip,
            ],
            'Complemento' => [
                'Payroll' => [
                    'Type' => 'O', // ordinaria
                    'PaymentDate' => $period->payment_date?->toDateString() ?? $period->end_date->toDateString(),
                    'InitialPaymentDate' => $period->start_date->toDateString(),
                    'FinalPaymentDate' => $period->end_date->toDateString(),
                    'DaysPaid' => $daysPaid,
                    'Issuer' => [
                        'EmployerRegistration' => (string) SystemSetting::get('company_employer_registration', ''),
                    ],
                    'Employee' => [
                        'Curp' => (string) $e->curp,
                        'SocialSecurityNumber' => (string) $e->imss_number,
                        'StartDateLaborRelations' => $e->hire_date?->toDateString(),
                        'ContractType' => (string) ($e->contract_type ?: '01'),
                        'RegimeType' => '02', // sueldos
                        'Unionized' => false,
                        'TypeOfJourney' => (string) ($e->workday_type ?: '01'),
                        'PositionRisk' => (string) SystemSetting::get('company_position_risk', '3'),
                        'Department' => (string) ($e->department?->name ?? ''),
                        'Position' => (string) ($e->position?->name ?? ''),
                        'FrequencyPayment' => '02', // semanal
                        'Bank' => $e->bank_code ?: null,
                        'BankAccount' => $e->clabe ?: null,
                        'DailySalary' => round((float) $e->daily_salary_computed, 2),
                        'SalaryIntegrated' => round((float) $e->sdi, 2),
                    ],
                    'Perceptions' => ['Details' => $perceptions],
                    'Deductions' => ['Details' => $deductions],
                    'OtherPayments' => $otherPayments,
                ],
            ],
        ];
    }

    private function perception(string $type, string $description, float $taxed, float $exempt): array
    {
        return [
            'PerceptionType' => $type,
            'Code' => $type,
            'Description' => $description,
            'TaxedAmount' => round(max(0, $taxed), 2),
            'ExemptAmount' => round(max(0, $exempt), 2),
        ];
    }

    private function deduction(string $type, string $description, float $amount): array
    {
        return [
            'DeduccionType' => $type,
            'Code' => $type,
            'Description' => $description,
            'Amount' => round(max(0, $amount), 2),
        ];
    }
}
