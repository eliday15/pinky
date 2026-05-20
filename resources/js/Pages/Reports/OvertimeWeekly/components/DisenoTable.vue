<script setup>
import { computed } from 'vue';
import { formatDate, formatHours } from '../format';
import OvertimeCell from './OvertimeCell.vue';
import OvertimeLegend from './OvertimeLegend.vue';
import { cellPending } from '../cells';

const props = defineProps({ report: Object });

/** Pending hours are attributed to whichever side (M or V) was active that day,
 *  matching how is_night_shift decides where approved OT lands. */
const pendingForM = (day) => (day?.is_night_shift ? 0 : cellPending(day));
const pendingForV = (day) => (day?.is_night_shift ? cellPending(day) : 0);

const colSums = computed(() => {
    const sums = {};
    props.report.dates.forEach((d) => {
        let m = 0, v = 0, mp = 0, vp = 0;
        props.report.rows.forEach((r) => {
            m += r.days[d].m_hours;
            v += r.days[d].v_hours;
            mp += pendingForM(r.days[d]);
            vp += pendingForV(r.days[d]);
        });
        sums[d] = { m, v, mp, vp };
    });
    return sums;
});
</script>

<template>
    <div>
        <OvertimeLegend />
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
                        <td class="border px-2 py-2 text-right align-top">
                            <OvertimeCell :approved="row.days[d].m_hours" :pending="pendingForM(row.days[d])" />
                        </td>
                        <td class="border px-2 py-2 text-right align-top">
                            <OvertimeCell :approved="row.days[d].v_hours" :pending="pendingForV(row.days[d])" />
                        </td>
                    </template>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="row.totals.total_hours" :pending="row.totals.pending_hours || 0" :show-zero="false" />
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
                    <template v-for="d in report.dates" :key="d">
                        <td class="border px-2 py-2 text-right align-top">
                            <OvertimeCell :approved="colSums[d].m" :pending="colSums[d].mp" />
                        </td>
                        <td class="border px-2 py-2 text-right align-top">
                            <OvertimeCell :approved="colSums[d].v" :pending="colSums[d].vp" />
                        </td>
                    </template>
                    <td class="border px-3 py-2 text-right align-top">
                        <OvertimeCell :approved="report.totals.total_hours" :pending="report.totals.pending_hours || 0" :show-zero="false" />
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
