<script setup>
import { computed, inject, ref } from 'vue';
import { formatDate, formatHours } from '../format';
import OvertimeCell from './OvertimeCell.vue';
import OvertimeLegend from './OvertimeLegend.vue';
import ExtraConceptsCell from './ExtraConceptsCell.vue';
import { cellApproved, cellPending } from '../cells';

const props = defineProps({ report: Object });

const showObservations = inject('showObservations', ref(true));
const money = (value) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value || 0);

const colSums = computed(() => {
    const approved = {};
    const pending = {};
    props.report.dates.forEach((d) => {
        approved[d] = props.report.rows.reduce((acc, r) => acc + cellApproved(r.days[d]), 0);
        pending[d] = props.report.rows.reduce((acc, r) => acc + cellPending(r.days[d]), 0);
    });
    return { approved, pending };
});
</script>

<template>
    <div>
        <OvertimeLegend />
        <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-50">
                <tr>
                    <th class="border px-3 py-2 text-left">NOMBRE</th>
                    <th v-if="report.is_consolidated" class="border px-3 py-2 text-left">DEPARTAMENTO</th>
                    <th v-for="d in report.dates" :key="d" class="border px-3 py-2">{{ formatDate(d) }}</th>
                    <th class="border px-3 py-2">TOTAL HORAS</th>
                    <th class="border px-3 py-2">{{ report.weekend_unit_hours ? 'FINES DE SEMANA' : 'FIN DE SEMANA' }}</th>
                    <th class="border px-3 py-2">COMIDA</th>
                    <th class="border px-3 py-2">VELADA</th>
                    <th class="border px-3 py-2">CENA</th>
                    <th class="border px-3 py-2 text-left">OTROS CONCEPTOS</th>
                    <th v-if="report.includes_amounts" class="border px-3 py-2 text-left">MONTO APROBADO</th>
                    <th v-if="showObservations" class="border px-3 py-2 text-left">OBSERVACIONES</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.rows" :key="row.employee.id" class="hover:bg-gray-50">
                    <td class="border px-3 py-2 whitespace-nowrap">{{ row.employee.full_name }}</td>
                    <td v-if="report.is_consolidated" class="border px-3 py-2 whitespace-nowrap">{{ row.department.name }}</td>
                    <td v-for="d in report.dates" :key="d" class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="cellApproved(row.days[d])" :pending="cellPending(row.days[d])" />
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="row.totals.total_hours" :pending="row.totals.pending_hours || 0" :show-zero="false" />
                    </td>
                    <!-- Conteo REAL de fines (bloques en Almacén; T+ = 1 y 12 h = doble
                         en los demás, Dani 2026-08-25) — lo mismo que paga la nómina. -->
                    <td class="border px-3 py-2 text-right">{{ row.totals.weekend_units ?? formatHours(row.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.comida_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.comida_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.velada_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.velada_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.cena_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.cena_count }}
                    </td>
                    <td class="border px-3 py-2 max-w-xs"><ExtraConceptsCell :items="row.extra_concepts" :show-amounts="report.includes_amounts" /></td>
                    <td v-if="report.includes_amounts" class="border px-3 py-2 text-xs min-w-52">
                        <div v-for="concept in row.compensation.concepts" :key="`${concept.code}-${concept.name}`" class="flex justify-between gap-3">
                            <span>{{ concept.code || concept.name }}</span><strong>{{ money(concept.amount) }}</strong>
                        </div>
                        <div class="flex justify-between gap-3 mt-1 pt-1 border-t"><span>Total</span><strong>{{ money(row.compensation.total) }}</strong></div>
                    </td>
                    <td v-if="showObservations" class="border px-3 py-2 text-xs text-gray-600 max-w-xs">{{ row.observations }}</td>
                </tr>
                <tr class="bg-gray-50 font-semibold">
                    <td class="border px-3 py-2">TOTAL</td>
                    <td v-if="report.is_consolidated" class="border px-3 py-2"></td>
                    <td v-for="d in report.dates" :key="d" class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="colSums.approved[d]" :pending="colSums.pending[d]" />
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="report.totals.total_hours" :pending="report.totals.pending_hours || 0" :show-zero="false" />
                    </td>
                    <td class="border px-3 py-2 text-right">{{ report.totals.weekend_units ?? formatHours(report.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.comida_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.velada_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.cena_count }}</td>
                    <td class="border px-3 py-2"><ExtraConceptsCell :items="report.totals.extra_concepts" :show-amounts="report.includes_amounts" /></td>
                    <td v-if="report.includes_amounts" class="border px-3 py-2 text-right">{{ money(report.totals.compensation.total) }}</td>
                    <td v-if="showObservations" class="border px-3 py-2"></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
