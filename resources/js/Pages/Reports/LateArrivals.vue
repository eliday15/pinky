<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    startDate: String,
    endDate: String,
    byEmployee: Array,
    summary: Object,
});

const dateRange = ref({ start: props.startDate, end: props.endDate });

const applyFilter = () => {
    router.get(route('reports.lateArrivals'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

const formatDate = (date) => new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
const formatShortDate = (date) => new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });
</script>

<template>
    <Head title="Reporte de Retardos" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte de Retardos</h2>
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
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ summary.total_late_records }}</p>
                <p class="text-xs text-gray-500">Total Retardos</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.employees_with_lates }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-orange-600">{{ summary.total_late_minutes }}</p>
                <p class="text-xs text-gray-500">Minutos Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.avg_late_minutes }}</p>
                <p class="text-xs text-gray-500">Promedio Min.</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.critical_employees }}</p>
                <p class="text-xs text-gray-500">Criticos (6+)</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Min. Totales</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Promedio</th>
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
                            <span :class="['px-2 py-1 rounded-full text-sm font-bold', row.late_count >= 6 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800']">
                                {{ row.late_count }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">{{ row.total_late_minutes }} min</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">{{ row.avg_late_minutes }} min</td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-1 max-w-xs">
                                <span v-for="d in row.dates.slice(0, 5)" :key="d.date" class="px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded" :title="`${d.minutes} min - ${d.check_in}`">
                                    {{ formatShortDate(d.date) }}
                                </span>
                                <span v-if="row.dates.length > 5" class="px-2 py-0.5 bg-gray-200 text-gray-600 text-xs rounded">+{{ row.dates.length - 5 }}</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">No hay retardos registrados</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-sm text-yellow-800"><strong>Nota:</strong> 6 retardos en el mes generan 1 falta automática.</p>
        </div>
    </AppLayout>
</template>
