<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    startDate: String,
    endDate: String,
    departments: Array,
    summary: Object,
});

const dateRange = ref({ start: props.startDate, end: props.endDate });

const applyFilter = () => {
    router.get(route('reports.departmentComparison'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

const formatDate = (date) => new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });

const getAttendanceColor = (rate) => {
    if (rate >= 95) return 'text-green-600';
    if (rate >= 85) return 'text-yellow-600';
    return 'text-red-600';
};

const getPunctualityColor = (rate) => {
    if (rate >= 90) return 'text-green-600';
    if (rate >= 75) return 'text-yellow-600';
    return 'text-red-600';
};
</script>

<template>
    <Head title="Comparativa por Departamento" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Comparativa por Departamento</h2>
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

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_departments }}</p>
                <p class="text-xs text-gray-500">Departamentos</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_hours }}h</p>
                <p class="text-xs text-gray-500">Horas Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ summary.avg_attendance_rate }}%</p>
                <p class="text-xs text-gray-500">Asistencia Prom.</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Departamento</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Empleados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ausencias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">% Asistencia</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">% Puntualidad</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="dept in departments" :key="dept.name" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-sm font-medium text-gray-900">{{ dept.name }}</p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">{{ dept.employee_count }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">{{ dept.total_hours }}h</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600 font-medium">{{ dept.overtime_hours }}h</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-red-600">{{ dept.absent_days }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-yellow-600">{{ dept.late_days }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span :class="[getAttendanceColor(dept.attendance_rate), 'font-bold']">{{ dept.attendance_rate }}%</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span :class="[getPunctualityColor(dept.punctuality_rate), 'font-bold']">{{ dept.punctuality_rate }}%</span>
                        </td>
                    </tr>
                    <tr v-if="departments.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">No hay datos</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
