<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';

const props = defineProps({
    startDate: String,
    endDate: String,
    byEmployee: Array,
    summary: Object,
    settings: Object,
});

const dateRange = ref({ start: props.startDate, end: props.endDate });

const applyFilter = () => {
    router.get(route('reports.faltas'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

const formatDate = (date) => fmtDate(date, { day: 'numeric', month: 'short', year: 'numeric' });
const formatShortDate = (date) => fmtDate(date, { day: 'numeric', month: 'short' });
</script>

<template>
    <Head title="Reporte de Faltas" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte de Faltas</h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">&larr; Volver a reportes</Link>
        </div>

        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                    <input v-model="dateRange.start" type="date" class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                    <input v-model="dateRange.end" type="date" class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                </div>
                <button @click="applyFilter" class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700">Aplicar</button>
                <a :href="route('reports.export.faltas', { start_date: dateRange.start, end_date: dateRange.end })"
                   class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                    Exportar Excel
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.total_faltas }}</p>
                <p class="text-xs text-gray-500">Total Faltas</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.employees_with_faltas }}</p>
                <p class="text-xs text-gray-500">Empleados con Faltas</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-700">{{ summary.no_show_faltas }}</p>
                <p class="text-xs text-gray-500">Inasistencias</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-orange-600">{{ summary.threshold_faltas }}</p>
                <p class="text-xs text-gray-500">Por Umbral</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ summary.retardo_faltas }}</p>
                <p class="text-xs text-gray-500">Faltas por Retardos</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Inasistencias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Por Umbral</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Por Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fechas</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="row in byEmployee" :key="row.employee?.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">{{ row.employee?.full_name?.charAt(0) || '?' }}</span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ row.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ row.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span v-if="row.no_show_faltas > 0" class="px-2 py-1 rounded-full text-sm font-bold bg-red-100 text-red-800">
                                {{ row.no_show_faltas }}
                            </span>
                            <span v-else class="text-sm text-gray-400">0</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span v-if="row.threshold_faltas > 0" class="px-2 py-1 rounded-full text-sm font-bold bg-orange-100 text-orange-800">
                                {{ row.threshold_faltas }}
                            </span>
                            <span v-else class="text-sm text-gray-400">0</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span v-if="row.retardo_faltas > 0" class="px-2 py-1 rounded-full text-sm font-bold bg-yellow-100 text-yellow-800">
                                {{ row.retardo_faltas }}
                            </span>
                            <span v-else class="text-sm text-gray-400">0</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="px-2 py-1 rounded-full text-sm font-bold bg-red-100 text-red-800">
                                {{ row.total_faltas }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-1 max-w-xs">
                                <span v-for="d in row.no_show_dates.slice(0, 5)" :key="'ns-'+d" class="px-2 py-0.5 bg-red-50 text-red-700 text-xs rounded" title="Inasistencia">
                                    {{ formatShortDate(d) }}
                                </span>
                                <span v-for="d in row.threshold_dates.slice(0, 5)" :key="'th-'+d" class="px-2 py-0.5 bg-orange-50 text-orange-700 text-xs rounded" title="Por umbral de retardo/salida">
                                    {{ formatShortDate(d) }}
                                </span>
                                <span v-if="row.dates.length > 10" class="px-2 py-0.5 bg-gray-200 text-gray-600 text-xs rounded">+{{ row.dates.length - 10 }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">No hay faltas registradas en este periodo</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-red-50 border border-red-200 rounded-lg p-4 space-y-1">
            <p class="text-sm text-red-800">
                <strong>Nota:</strong> Una falta se genera al no presentarse, llegar {{ settings.maxLate }}+ min tarde,
                <template v-if="settings.earlyIsAbsence">salir {{ settings.earlyThreshold }}+ min antes sin autorizacion, </template>
                o acumular {{ settings.lateToAbsence }} retardos al mes.
            </p>
            <div class="flex flex-wrap gap-3 text-xs mt-2">
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-red-200 inline-block"></span> Inasistencia (sin registro)</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-orange-200 inline-block"></span> Por umbral (retardo {{ settings.maxLate }}+ min<template v-if="settings.earlyIsAbsence"> o salida {{ settings.earlyThreshold }}+ min antes</template>)</span>
                <span class="inline-flex items-center gap-1"><span class="w-3 h-3 rounded bg-yellow-200 inline-block"></span> Por retardos acumulados</span>
            </div>
        </div>
    </AppLayout>
</template>
