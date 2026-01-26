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
    router.get(route('reports.productivity'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

const getEfficiencyColor = (efficiency) => {
    if (efficiency >= 100) return 'text-green-600';
    if (efficiency >= 90) return 'text-blue-600';
    if (efficiency >= 75) return 'text-yellow-600';
    return 'text-red-600';
};

const getEfficiencyBg = (efficiency) => {
    if (efficiency >= 100) return 'bg-green-500';
    if (efficiency >= 90) return 'bg-blue-500';
    if (efficiency >= 75) return 'bg-yellow-500';
    return 'bg-red-500';
};

const getPunctualityColor = (score) => {
    if (score >= 90) return 'bg-green-100 text-green-800';
    if (score >= 70) return 'bg-yellow-100 text-yellow-800';
    return 'bg-red-100 text-red-800';
};

const getPerformanceRating = (efficiency, punctuality) => {
    const avg = (efficiency + punctuality) / 2;
    if (avg >= 95) return { label: 'Excelente', class: 'bg-green-500 text-white' };
    if (avg >= 85) return { label: 'Bueno', class: 'bg-blue-500 text-white' };
    if (avg >= 70) return { label: 'Regular', class: 'bg-yellow-500 text-white' };
    return { label: 'Bajo', class: 'bg-red-500 text-white' };
};
</script>

<template>
    <Head title="Reporte de Productividad" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte de Productividad</h2>
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
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p :class="['text-2xl font-bold', getEfficiencyColor(summary.avg_efficiency)]">{{ summary.avg_efficiency }}%</p>
                <p class="text-xs text-gray-500">Eficiencia Prom.</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ summary.avg_punctuality }}%</p>
                <p class="text-xs text-gray-500">Puntualidad Prom.</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_hours }}h</p>
                <p class="text-xs text-gray-500">Horas Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_overtime }}h</p>
                <p class="text-xs text-gray-500">Horas Extra</p>
            </div>
        </div>

        <!-- Top 5 Performers -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Top 5 - Mayor Eficiencia</h3>
                <div class="space-y-3">
                    <div v-for="(emp, idx) in byEmployee.slice(0, 5)" :key="emp.employee?.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold mr-3"
                                  :class="idx === 0 ? 'bg-yellow-400 text-yellow-900' : idx === 1 ? 'bg-gray-300 text-gray-700' : idx === 2 ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-600'">
                                {{ idx + 1 }}
                            </span>
                            <div>
                                <p class="font-medium text-gray-800">{{ emp.employee?.full_name }}</p>
                                <p class="text-xs text-gray-500">{{ emp.employee?.department?.name }}</p>
                            </div>
                        </div>
                        <span :class="[getEfficiencyColor(emp.efficiency), 'font-bold']">{{ emp.efficiency }}%</span>
                    </div>
                    <p v-if="byEmployee.length === 0" class="text-center text-gray-500 py-4">Sin datos</p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Necesitan Atencion</h3>
                <div class="space-y-3">
                    <div v-for="emp in byEmployee.filter(e => e.efficiency < 80 || e.punctuality_score < 70).slice(0, 5)"
                         :key="emp.employee?.id"
                         class="flex items-center justify-between p-3 bg-red-50 rounded-lg border border-red-100">
                        <div>
                            <p class="font-medium text-gray-800">{{ emp.employee?.full_name }}</p>
                            <p class="text-xs text-gray-500">{{ emp.late_count }} retardos, {{ emp.absent_count }} faltas</p>
                        </div>
                        <div class="text-right">
                            <p :class="[getEfficiencyColor(emp.efficiency), 'text-sm font-bold']">{{ emp.efficiency }}% efic.</p>
                            <p class="text-xs text-gray-500">{{ emp.punctuality_score }}% punt.</p>
                        </div>
                    </div>
                    <p v-if="byEmployee.filter(e => e.efficiency < 80 || e.punctuality_score < 70).length === 0" class="text-center text-green-600 py-4">
                        Todos los empleados tienen buen rendimiento
                    </p>
                </div>
            </div>
        </div>

        <!-- Full Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Detalle por Empleado</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Faltas</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Eficiencia</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Puntualidad</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Rating</th>
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
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">{{ row.worked_days }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ row.total_hours }}h
                            <span class="text-xs text-gray-400">/{{ row.expected_hours }}h</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-green-600 font-medium">{{ row.overtime_hours }}h</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span :class="['px-2 py-0.5 text-xs rounded', row.late_count > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-500']">
                                {{ row.late_count }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span :class="['px-2 py-0.5 text-xs rounded', row.absent_count > 0 ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-500']">
                                {{ row.absent_count }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex items-center justify-center">
                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                    <div :class="[getEfficiencyBg(row.efficiency), 'h-2 rounded-full']" :style="{ width: Math.min(row.efficiency, 100) + '%' }"></div>
                                </div>
                                <span :class="[getEfficiencyColor(row.efficiency), 'text-sm font-bold']">{{ row.efficiency }}%</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span :class="[getPunctualityColor(row.punctuality_score), 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ row.punctuality_score }}%
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span :class="[getPerformanceRating(row.efficiency, row.punctuality_score).class, 'px-2 py-1 text-xs font-medium rounded']">
                                {{ getPerformanceRating(row.efficiency, row.punctuality_score).label }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">No hay datos de productividad</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="font-medium text-blue-800 mb-2">Como se calcula:</h4>
            <ul class="text-sm text-blue-700 space-y-1">
                <li><strong>Eficiencia:</strong> Horas trabajadas / Horas esperadas x 100</li>
                <li><strong>Puntualidad:</strong> 100 - (Retardos x 5) - (Faltas x 10)</li>
                <li><strong>Rating:</strong> Promedio de eficiencia y puntualidad (Excelente 95%+, Bueno 85%+, Regular 70%+)</li>
            </ul>
        </div>
    </AppLayout>
</template>
