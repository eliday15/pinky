<script setup>
import { computed } from 'vue';
import { formatDate, formatHours } from '../format';

const props = defineProps({ report: Object });

const colSums = computed(() => {
    const approved = {};
    const pending = {};
    props.report.dates.forEach((d) => {
        approved[d] = props.report.rows.reduce(
            (acc, r) => acc + (r.days[d].overtime_hours + r.days[d].velada_hours),
            0,
        );
        pending[d] = props.report.rows.reduce(
            (acc, r) => acc + (r.days[d].pending_overtime_hours || 0),
            0,
        );
    });
    return { approved, pending };
});

const cellApproved = (day) => (day?.overtime_hours || 0) + (day?.velada_hours || 0);
const cellPending = (day) => day?.pending_overtime_hours || 0;
</script>

<template>
    <div>
        <div class="px-4 pt-3 pb-2 text-xs text-gray-600 flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-3 h-3 rounded bg-emerald-100 border border-emerald-300"></span>
                <span><strong>1.5 ✓</strong> = autorizado y aprobado</span>
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="inline-block w-3 h-3 rounded bg-amber-100 border border-amber-300"></span>
                <span><strong>+0.5</strong> = trabajado y detectado, sin aprobar</span>
            </span>
        </div>
        <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-50">
                <tr>
                    <th class="border px-3 py-2 text-left">NOMBRE</th>
                    <th v-for="d in report.dates" :key="d" class="border px-3 py-2">{{ formatDate(d) }}</th>
                    <th class="border px-3 py-2">TOTAL HORAS</th>
                    <th class="border px-3 py-2">FIN DE SEMANA</th>
                    <th class="border px-3 py-2">COMIDA</th>
                    <th class="border px-3 py-2">VELADA</th>
                    <th class="border px-3 py-2">CENA</th>
                    <th class="border px-3 py-2 text-left">OBSERVACIONES</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in report.rows" :key="row.employee.id" class="hover:bg-gray-50">
                    <td class="border px-3 py-2 whitespace-nowrap">{{ row.employee.full_name }}</td>
                    <td
                        v-for="d in report.dates"
                        :key="d"
                        class="border px-2 py-2 text-right align-top"
                    >
                        <div v-if="cellApproved(row.days[d]) > 0" class="text-emerald-700 font-medium">
                            {{ formatHours(cellApproved(row.days[d])) }} <span aria-label="aprobado">✓</span>
                        </div>
                        <div v-if="cellPending(row.days[d]) > 0" class="text-amber-700 text-xs">
                            +{{ formatHours(cellPending(row.days[d])) }}
                        </div>
                        <div v-if="cellApproved(row.days[d]) === 0 && cellPending(row.days[d]) === 0"
                            class="text-gray-300">0</div>
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <div class="font-medium">{{ formatHours(row.totals.total_hours) }}</div>
                        <div v-if="(row.totals.pending_hours || 0) > 0" class="text-amber-700 text-xs">
                            +{{ formatHours(row.totals.pending_hours) }}
                        </div>
                    </td>
                    <td class="border px-3 py-2 text-right">{{ formatHours(row.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.comida_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.comida_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.velada_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.velada_count }}
                    </td>
                    <td class="border px-3 py-2 text-center" :class="row.totals.cena_count === 0 ? 'text-gray-300' : ''">
                        {{ row.totals.cena_count }}
                    </td>
                    <td class="border px-3 py-2 text-xs text-gray-600 max-w-xs">{{ row.observations }}</td>
                </tr>
                <tr class="bg-gray-50 font-semibold">
                    <td class="border px-3 py-2">TOTAL</td>
                    <td v-for="d in report.dates" :key="d" class="border px-2 py-2 text-right align-top">
                        <div v-if="colSums.approved[d] > 0" class="text-emerald-700">{{ formatHours(colSums.approved[d]) }} ✓</div>
                        <div v-if="colSums.pending[d] > 0" class="text-amber-700 text-xs">+{{ formatHours(colSums.pending[d]) }}</div>
                        <div v-if="colSums.approved[d] === 0 && colSums.pending[d] === 0" class="text-gray-300">0</div>
                    </td>
                    <td class="border px-3 py-2 text-right align-top">
                        <div>{{ formatHours(report.totals.total_hours) }}</div>
                        <div v-if="(report.totals.pending_hours || 0) > 0" class="text-amber-700 text-xs">
                            +{{ formatHours(report.totals.pending_hours) }}
                        </div>
                    </td>
                    <td class="border px-3 py-2 text-right">{{ formatHours(report.totals.weekend_hours) }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.comida_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.velada_count }}</td>
                    <td class="border px-3 py-2 text-center">{{ report.totals.cena_count }}</td>
                    <td class="border px-3 py-2"></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
