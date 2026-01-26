<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    employees: Array,
    departments: Array,
    selectedDepartment: [Number, String],
    summary: Object,
});

const department = ref(props.selectedDepartment || '');

watch(department, (newDept) => {
    router.get(route('reports.vacationBalance'), { department: newDept || undefined }, { preserveState: true, replace: true });
});

const getProgressColor = (percentage) => {
    if (percentage >= 80) return 'bg-red-500';
    if (percentage >= 50) return 'bg-yellow-500';
    return 'bg-green-500';
};
</script>

<template>
    <Head title="Saldo de Vacaciones" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Saldo de Vacaciones</h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">&larr; Volver a reportes</Link>
        </div>

        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Filtrar por Departamento</label>
            <select v-model="department" class="w-full max-w-md rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500">
                <option value="">Todos los departamentos</option>
                <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
            </select>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_entitled }}</p>
                <p class="text-xs text-gray-500">Dias Derecho</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-orange-600">{{ summary.total_used }}</p>
                <p class="text-xs text-gray-500">Dias Usados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_available }}</p>
                <p class="text-xs text-gray-500">Disponibles</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ summary.avg_usage_percentage }}%</p>
                <p class="text-xs text-gray-500">Uso Promedio</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Derecho</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Usados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Disponibles</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uso</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="row in employees" :key="row.employee?.id" class="hover:bg-gray-50">
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
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">{{ row.entitled }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">{{ row.used }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span :class="['px-2 py-1 rounded-full text-sm font-bold', row.available <= 0 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800']">
                                {{ row.available }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                    <div :class="[getProgressColor(row.percentage), 'h-2 rounded-full']" :style="{ width: Math.min(row.percentage, 100) + '%' }"></div>
                                </div>
                                <span class="text-xs text-gray-500">{{ row.percentage }}%</span>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="employees.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">No hay empleados</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
