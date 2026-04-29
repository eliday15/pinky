<script setup>
import { computed } from 'vue';
import { dayLabel, formatDate, formatHours } from '../format';

const props = defineProps({ report: Object });

const dailyCells = computed(() => {
    return props.report.dates.map((date) => {
        const cells = props.report.rows.map((r) => r.days[date].overtime_hours + r.days[date].velada_hours);
        const sum = cells.reduce((a, b) => a + b, 0);
        return {
            date,
            label: dayLabel(date),
            short: formatDate(date).slice(0, 5),
            cells,
            sum,
        };
    });
});

const summaryRows = computed(() => {
    const weekStart = formatDate(props.report.week_start);
    const builders = [
        { label: 'TOTAL', kind: 'hours', extract: (r) => r.totals.total_hours },
        { label: 'CENA', kind: 'count', extract: (r) => r.totals.cena_count },
        { label: `VELADA ${weekStart}`, kind: 'count', extract: (r) => r.totals.velada_count },
        { label: `CENA ${weekStart}`, kind: 'count', extract: (r) => r.totals.cena_count },
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
</script>

<template>
    <div class="space-y-6 p-4">
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
                    <td
                        v-for="(value, idx) in day.cells"
                        :key="idx"
                        class="border px-3 py-2 text-right"
                        :class="value <= 0 ? 'text-gray-300' : ''"
                    >
                        {{ formatHours(value) }}
                    </td>
                    <td class="border px-3 py-2 text-right font-medium">{{ formatHours(day.sum) }}</td>
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
    </div>
</template>
