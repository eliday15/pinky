<script setup>
import { computed } from 'vue';
import { dayLabel, formatDate, formatHours } from '../format';
import OvertimeCell from './OvertimeCell.vue';
import OvertimeLegend from './OvertimeLegend.vue';
import ExtraConceptsCell from './ExtraConceptsCell.vue';
import { cellApproved, cellPending } from '../cells';

const props = defineProps({ report: Object });

const dailyCells = computed(() => {
    return props.report.dates.map((date) => {
        const cells = props.report.rows.map((r) => ({
            approved: cellApproved(r.days[date]),
            pending: cellPending(r.days[date]),
        }));
        const approvedSum = cells.reduce((a, c) => a + c.approved, 0);
        const pendingSum = cells.reduce((a, c) => a + c.pending, 0);
        return {
            date,
            label: dayLabel(date),
            short: formatDate(date).slice(0, 5),
            cells,
            approvedSum,
            pendingSum,
        };
    });
});

const summaryRows = computed(() => {
    // Conceptos del periodo, uno por fila y sin fecha confusa (antes salía
    // "VELADA 16/06" y la CENA duplicada con la fecha de inicio de semana).
    const builders = [
        { label: 'TOTAL', kind: 'hours', extract: (r) => r.totals.total_hours },
        { label: 'VELADA', kind: 'count', extract: (r) => r.totals.velada_count },
        { label: 'CENA', kind: 'count', extract: (r) => r.totals.cena_count },
        { label: 'FIN DE SEMANA', kind: 'hours', extract: (r) => r.totals.weekend_hours },
        { label: 'COMIDA', kind: 'count', extract: (r) => r.totals.comida_count },
    ];
    return builders.map((b) => {
        const cells = props.report.rows.map(b.extract);
        const sum = cells.reduce((a, c) => a + c, 0);
        return { ...b, cells, sum };
    });
});

const obsRows = computed(() => props.report.rows.filter((r) => (r.observations || '').trim() !== ''));
const extraRows = computed(() => props.report.rows.filter((r) => (r.extra_concepts || []).length > 0));
</script>

<template>
    <div class="space-y-6 p-4">
        <OvertimeLegend />
        <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-50">
                <tr>
                    <th class="border px-3 py-2 text-left">CONCEPTO</th>
                    <th v-for="row in report.rows" :key="row.employee.id" class="border px-3 py-2 align-bottom">
                        <div class="text-xs">{{ row.employee.full_name }}</div>
                    </th>
                    <th class="border px-3 py-2">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in dailyCells" :key="day.date">
                    <td class="border px-3 py-2 font-medium">
                        {{ day.label }} <span class="text-xs text-gray-500">({{ day.short }})</span>
                    </td>
                    <td v-for="(cell, idx) in day.cells" :key="idx" class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="cell.approved" :pending="cell.pending" />
                    </td>
                    <td class="border px-2 py-2 text-right align-top">
                        <OvertimeCell :approved="day.approvedSum" :pending="day.pendingSum" />
                    </td>
                </tr>
                <tr v-for="(srow, idx) in summaryRows" :key="`s-${idx}`" class="bg-gray-50 font-semibold">
                    <td class="border px-3 py-2">{{ srow.label }}</td>
                    <td
                        v-for="(value, i) in srow.cells"
                        :key="i"
                        class="border px-3 py-2"
                        :class="[
                            srow.kind === 'hours' ? 'text-right' : 'text-center',
                            value <= 0 ? 'text-gray-300' : '',
                        ]"
                    >
                        {{ srow.kind === 'hours' ? formatHours(value) : value }}
                    </td>
                    <td class="border px-3 py-2" :class="srow.kind === 'hours' ? 'text-right' : 'text-center'">
                        {{ srow.kind === 'hours' ? formatHours(srow.sum) : srow.sum }}
                    </td>
                </tr>
            </tbody>
        </table>

        <div v-if="obsRows.length">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">OBSERVACIONES</h3>
            <table class="min-w-full text-sm border-collapse">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="border px-3 py-2 text-left w-1/3">EMPLEADO</th>
                        <th class="border px-3 py-2 text-left">NOTA</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in obsRows" :key="row.employee.id">
                        <td class="border px-3 py-2">{{ row.employee.full_name }}</td>
                        <td class="border px-3 py-2 text-xs text-gray-600">{{ row.observations }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="extraRows.length">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">OTROS CONCEPTOS</h3>
            <table class="min-w-full text-sm border-collapse">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="border px-3 py-2 text-left w-1/3">EMPLEADO</th>
                        <th class="border px-3 py-2 text-left">CONCEPTOS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in extraRows" :key="row.employee.id">
                        <td class="border px-3 py-2">{{ row.employee.full_name }}</td>
                        <td class="border px-3 py-2"><ExtraConceptsCell :items="row.extra_concepts" /></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
