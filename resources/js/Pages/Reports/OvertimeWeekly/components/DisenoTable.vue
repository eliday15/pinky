<script setup>
import { computed } from 'vue';
import { formatDate, formatHours } from '../format';

const props = defineProps({ report: Object });

const colSums = computed(() => {
    const sums = {};
    props.report.dates.forEach((d) => {
        let m = 0;
        let v = 0;
        props.report.rows.forEach((r) => {
            m += r.days[d].m_hours;
            v += r.days[d].v_hours;
        });
        sums[d] = { m, v };
    });
    return sums;
});
</script>

<template>
    <table class="min-w-full text-sm border-collapse">
        <thead class="bg-gray-50">
            <tr>
                <th rowspan="2" class="border px-3 py-2 text-left">NOMBRE</th>
                <th v-for="d in report.dates" :key="d" colspan="2" class="border px-3 py-2">
                    {{ formatDate(d) }}
                </th>
                <th rowspan="2" class="border px-3 py-2">TOTAL HORAS</th>
                <th rowspan="2" class="border px-3 py-2">FIN DE SEMANA</th>
                <th rowspan="2" class="border px-3 py-2">COMIDA</th>
                <th rowspan="2" class="border px-3 py-2">VELADA</th>
                <th rowspan="2" class="border px-3 py-2">CENA</th>
                <th rowspan="2" class="border px-3 py-2 text-left">OBSERVACIONES</th>
            </tr>
            <tr>
                <template v-for="d in report.dates" :key="d">
                    <th class="border px-2 py-1 text-xs">M</th>
                    <th class="border px-2 py-1 text-xs">V</th>
                </template>
            </tr>
        </thead>
        <tbody>
            <tr v-for="row in report.rows" :key="row.employee.id" class="hover:bg-gray-50">
                <td class="border px-3 py-2 whitespace-nowrap">{{ row.employee.full_name }}</td>
                <template v-for="d in report.dates" :key="d">
                    <td
                        class="border px-2 py-2 text-right"
                        :class="row.days[d].m_hours <= 0 ? 'text-gray-300' : ''"
                    >
                        {{ formatHours(row.days[d].m_hours) }}
                    </td>
                    <td
                        class="border px-2 py-2 text-right"
                        :class="row.days[d].v_hours <= 0 ? 'text-gray-300' : ''"
                    >
                        {{ formatHours(row.days[d].v_hours) }}
                    </td>
                </template>
                <td class="border px-3 py-2 text-right font-medium">{{ formatHours(row.totals.total_hours) }}</td>
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
                <template v-for="d in report.dates" :key="d">
                    <td class="border px-2 py-2 text-right">{{ formatHours(colSums[d].m) }}</td>
                    <td class="border px-2 py-2 text-right">{{ formatHours(colSums[d].v) }}</td>
                </template>
                <td class="border px-3 py-2 text-right">{{ formatHours(report.totals.total_hours) }}</td>
                <td class="border px-3 py-2 text-right">{{ formatHours(report.totals.weekend_hours) }}</td>
                <td class="border px-3 py-2 text-center">{{ report.totals.comida_count }}</td>
                <td class="border px-3 py-2 text-center">{{ report.totals.velada_count }}</td>
                <td class="border px-3 py-2 text-center">{{ report.totals.cena_count }}</td>
                <td class="border px-3 py-2"></td>
            </tr>
        </tbody>
    </table>
</template>
