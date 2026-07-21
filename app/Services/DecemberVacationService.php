<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/**
 * Vacaciones obligatorias de diciembre (Dani 2026-07-17).
 *
 * La empresa cierra en diciembre, así que cada año el Administrador define
 * cuántos días de vacaciones son OBLIGATORIOS para ese cierre — el mismo número
 * para toda la empresa. Esos días quedan APARTADOS: siguen siendo del
 * colaborador pero no se pueden solicitar en otra fecha.
 *
 * A los de NUEVO INGRESO (menos de un año) todavía no les corresponden días por
 * antigüedad, así que se les ADELANTAN para que no se queden sin sueldo durante
 * el cierre. Ese adelanto es una deuda que se salda sola cuando generan su
 * derecho (ver Employee::settleVacationAdvance()).
 *
 * Los días apartados NO se marcan como usados: quedan bloqueados y RRHH los
 * captura como vacaciones cuando llega la fecha (decisión de Dani).
 */
class DecemberVacationService
{
    /** Días obligatorios configurados para el cierre de diciembre. */
    public const SETTING_DAYS = 'december_mandatory_vacation_days';

    /** Año al que corresponde la última aplicación. */
    public const SETTING_YEAR = 'december_mandatory_vacation_year';

    /** Días obligatorios configurados (0 = sin configurar). */
    public function configuredDays(): int
    {
        return (int) SystemSetting::get(self::SETTING_DAYS, 0);
    }

    /** Año de la última aplicación (null si nunca se aplicó). */
    public function appliedYear(): ?int
    {
        $year = SystemSetting::get(self::SETTING_YEAR);

        return $year !== null ? (int) $year : null;
    }

    /**
     * Calcular el reparto de un colaborador para N días obligatorios.
     *
     * Se aparta lo que su derecho alcanza; el faltante se ADELANTA sólo si es de
     * nuevo ingreso (los de antigüedad que ya gastaron sus días simplemente
     * apartan lo que les queda).
     *
     * @return array{reserved: int, advanced: int}
     */
    public function splitFor(Employee $employee, int $days): array
    {
        // Días que realmente tiene disponibles para apartar (sin contar lo ya
        // usado). El adelanto pendiente no entra aquí: es deuda, no saldo.
        $pool = max(0, (int) $employee->vacation_days_entitled - (int) $employee->vacation_days_used);

        $reserved = min($days, $pool);
        $shortfall = $days - $reserved;
        $advanced = ($shortfall > 0 && $employee->isNewHire()) ? $shortfall : 0;

        return ['reserved' => $reserved, 'advanced' => $advanced];
    }

    /**
     * Previsualizar el impacto sin escribir nada.
     *
     * @return array{total: int, con_derecho: int, con_adelanto: int, incompletos: int, dias_adelantados: int}
     */
    public function preview(int $days): array
    {
        $stats = ['total' => 0, 'con_derecho' => 0, 'con_adelanto' => 0, 'incompletos' => 0, 'dias_adelantados' => 0];

        foreach ($this->targetEmployees() as $employee) {
            $split = $this->splitFor($employee, $days);
            $stats['total']++;

            if ($split['advanced'] > 0) {
                $stats['con_adelanto']++;
                $stats['dias_adelantados'] += $split['advanced'];
            } elseif ($split['reserved'] >= $days) {
                $stats['con_derecho']++;
            } else {
                // No alcanza a cubrir los días obligatorios y no es de nuevo
                // ingreso: se aparta lo que le queda.
                $stats['incompletos']++;
            }
        }

        return $stats;
    }

    /**
     * Aplicar los días obligatorios a toda la empresa.
     *
     * Es RE-EJECUTABLE: recalcula desde cero (no acumula), así que cambiar el
     * número y volver a aplicar deja los saldos correctos. Sobrescribe cualquier
     * apartado capturado a mano.
     *
     * @return array{total: int, con_derecho: int, con_adelanto: int, incompletos: int, dias_adelantados: int}
     */
    public function apply(int $days): array
    {
        $stats = $this->preview($days);

        DB::transaction(function () use ($days) {
            foreach ($this->targetEmployees() as $employee) {
                $split = $this->splitFor($employee, $days);

                $employee->forceFill([
                    'vacation_days_reserved' => $split['reserved'],
                    'vacation_days_advanced' => $split['advanced'],
                ])->save();
            }

            $this->putSetting(self::SETTING_DAYS, $days);
            $this->putSetting(self::SETTING_YEAR, (int) now()->year);
        });

        return $stats;
    }

    /**
     * Escribir una setting garantizando que la fila exista.
     *
     * `SystemSetting::set()` sólo actualiza filas existentes (no las crea), así
     * que primero se asegura la fila y luego se usa set() para que invalide el
     * cache y el memo del proceso.
     */
    private function putSetting(string $key, int $value): void
    {
        SystemSetting::firstOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => 'integer', 'group' => 'general', 'label' => $key],
        );

        SystemSetting::set($key, $value);
    }

    /**
     * Deshacer la aplicación: libera los días apartados y el adelanto pendiente.
     *
     * Pensado para corregir ANTES de que se disfrute diciembre. No revierte los
     * adelantos que ya se saldaron (ésos ya pasaron a días usados).
     */
    public function clear(): int
    {
        $count = 0;

        DB::transaction(function () use (&$count) {
            foreach ($this->targetEmployees() as $employee) {
                if ((int) $employee->vacation_days_reserved === 0 && (int) $employee->vacation_days_advanced === 0) {
                    continue;
                }

                $employee->forceFill([
                    'vacation_days_reserved' => 0,
                    'vacation_days_advanced' => 0,
                ])->save();

                $count++;
            }

            $this->putSetting(self::SETTING_DAYS, 0);
        });

        return $count;
    }

    /**
     * Saldar las deudas de adelanto de todos los que ya generaron derecho.
     *
     * @return array{empleados: int, dias: int}
     */
    public function settleAdvances(): array
    {
        $employees = 0;
        $days = 0;

        foreach (Employee::where('vacation_days_advanced', '>', 0)->get() as $employee) {
            $settled = $employee->settleVacationAdvance();

            if ($settled > 0) {
                $employees++;
                $days += $settled;
            }
        }

        return ['empleados' => $employees, 'dias' => $days];
    }

    /**
     * Colaboradores a los que aplica el cierre: los activos.
     *
     * @return \Illuminate\Support\LazyCollection<int, Employee>
     */
    private function targetEmployees()
    {
        return Employee::where('status', 'active')->cursor();
    }
}
