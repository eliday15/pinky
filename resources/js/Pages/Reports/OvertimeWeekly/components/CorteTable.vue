<script setup>
import { computed } from 'vue';
import { formatDate, formatHours } from '../format';
import OvertimeCell from './OvertimeCell.vue';
import OvertimeLegend from './OvertimeLegend.vue';
import ExtraConceptsCell from './ExtraConceptsCell.vue';
import { cellApproved, cellPending } from '../cells';

const props = defineProps({ report: Object });

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
                    <th v-for="d in report.dates" :key="d" class="border px-3 py-2">{{ formatDate(d) }}</th>
                    <th class="border px-3 py-2">TOTAL HORAS</th>
                    <th class="border px-3 py-2">{{ report.weekend_unit_hours ? 'FINES DE SEMANA' : 'FIN DE SEMANA' }}</th>
                    <th class="border px-3 py-2">COMIDA</th>
                    <th class="border px-3 py-2">VELADA</th>
                    <th class="border px-3 py-2">CENA</th>
                    <th class="border px-3 py-2 text-left">OTROS CONCEPTOS</th>
                    <th class="border px-3 py-2 text-left">OBSERVACIONES</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.rows" :key="row.employee.id" class="hover:bg-gray-50">
                    <td class="border px-3 py-2 whitespace-nowrap">{{ row.employee.full_name }}</td>
                    <td v-for="d in report.dates" :key="d" class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="cellApproved(row.days[d])" :pending="cellPending(row.days[d])" />
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="row.totals.total_hours" :pending="row.totals.pending_hours || 0" :show-zero="false" />
                    </td>
                    <td class="border px-3 py-2 text-right">{{ report.weekend_unit_hours ? row.totals.weekend_units : formatHours(row.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.comida_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.comida_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.velada_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.velada_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.cena_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.cena_count }}
                    </td>
                    <td class="border px-3 py-2 max-w-xs"><ExtraConceptsCell :items="row.extra_concepts" /></td>
                    <td class="border px-3 py-2 text-xs text-gray-600 max-w-xs">{{ row.observations }}</td>
                </tr>
                <tr class="bg-gray-50 font-semibold">
                    <td class="border px-3 py-2">TOTAL</td>
                    <td v-for="d in report.dates" :key="d" class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="colSums.approved[d]" :pending="colSums.pending[d]" />
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="report.totals.total_hours" :pending="report.totals.pending_hours || 0" :show-zero="false" />
                    </td>
                    <td class="border px-3 py-2 text-right">{{ report.weekend_unit_hours ? report.totals.weekend_units : formatHours(report.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.comida_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.velada_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.cena_count }}</td>
                    <td class="border px-3 py-2"><ExtraConceptsCell :items="report.totals.extra_concepts" /></td>
                    <td class="border px-3 py-2"></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
