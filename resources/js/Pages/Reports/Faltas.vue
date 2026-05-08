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
const formatMonth = (ym) => {
    const [y, m] = ym.split('-');
    return new Date(y, m - 1).toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
};
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Detalle</th>
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
                        <td class="px-4 py-3">
                            <div class="space-y-1">
                                <div v-for="d in row.no_show_dates" :key="'ns-'+d.date" class="flex items-center gap-1.5 text-xs">
                                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                                    <span class="text-gray-700 whitespace-nowrap">{{ formatShortDate(d.date) }}</span>
                                    <span class="text-red-600">— No se presentó</span>
                                </div>
                                <div v-for="d in row.threshold_dates" :key="'th-'+d.date" class="flex items-center gap-1.5 text-xs">
                                    <span class="w-2 h-2 rounded-full bg-orange-500 shrink-0"></span>
                                    <span class="text-gray-700 whitespace-nowrap">{{ formatShortDate(d.date) }}</span>
                                    <span class="text-orange-600">— {{ d.label }}</span>
                                </div>
                                <div v-for="detail in row.retardo_details" :key="'rd-'+detail.month" class="flex items-center gap-1.5 text-xs">
                                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                                    <span class="text-yellow-700">{{ detail.faltas }} falta{{ detail.faltas > 1 ? 's' : '' }} por {{ detail.late_count }} retardos</span>
                                    <span class="text-gray-400">({{ formatMonth(detail.month) }})</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">No hay faltas registradas en este periodo</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-gray-50 border border-gray-200 rounded-lg p-4 space-y-2">
            <p class="text-sm text-gray-700 font-medium">Tipos de falta:</p>
            <div class="space-y-1.5 text-xs text-gray-600">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-red-500 shrink-0"></span>
                    <span><strong class="text-red-700">No se presentó:</strong> el empleado no registró entrada ese día.</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-orange-500 shrink-0"></span>
                    <span><strong class="text-orange-700">Retardo excesivo:</strong> llegó {{ settings.maxLate }}+ min tarde, se cuenta como falta.</span>
                </div>
                <div v-if="settings.earlyIsAbsence" class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-orange-500 shrink-0"></span>
                    <span><strong class="text-orange-700">Salida temprana:</strong> salió {{ settings.earlyThreshold }}+ min antes sin autorización, se cuenta como falta.</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0"></span>
                    <span><strong class="text-yellow-700">Retardos acumulados:</strong> cada {{ settings.lateToAbsence }} retardos en el mes generan 1 falta.</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
