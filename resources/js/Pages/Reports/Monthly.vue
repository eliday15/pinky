<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    month: String,
    monthName: String,
    byEmployee: Array,
    byDepartment: Object,
    summary: Object,
});

const selectedMonth = ref(props.month);

watch(selectedMonth, (newMonth) => {
    router.get(route('reports.monthly'), { month: newMonth }, {
        preserveState: true,
        replace: true,
    });
});

const changeMonth = (delta) => {
    const [year, month] = selectedMonth.value.split('-').map(Number);
    const d = new Date(year, month - 1 + delta, 1);
    selectedMonth.value = d.toISOString().slice(0, 7);
};
</script>

<template>
    <Head title="Reporte Mensual" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte Mensual de Asistencia
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Month Selector -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex items-center justify-between">
                <button @click="changeMonth(-1)" class="p-2 hover:bg-gray-100 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div class="text-center">
                    <p class="text-xl font-medium text-gray-800 capitalize">{{ monthName }}</p>
                    <input
                        v-model="selectedMonth"
                        type="month"
                        class="mt-2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <button @click="changeMonth(1)" class="p-2 hover:bg-gray-100 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_hours }}h</p>
                <p class="text-xs text-gray-500">Horas Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_overtime }}h</p>
                <p class="text-xs text-gray-500">Horas Extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.total_absences }}</p>
                <p class="text-xs text-gray-500">Ausencias</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ summary.total_vacation_days }}</p>
                <p class="text-xs text-gray-500">Dias Vacaciones</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-pink-600">{{ summary.total_sick_days }}</p>
                <p class="text-xs text-gray-500">Dias Incapacidad</p>
            </div>
        </div>

        <!-- By Department -->
        <div v-if="Object.keys(byDepartment).length > 0" class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Resumen por Departamento</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="(data, dept) in byDepartment"
                    :key="dept"
                    class="bg-gray-50 rounded-lg p-4"
                >
                    <h4 class="font-medium text-gray-800">{{ dept }}</h4>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-gray-500">Empleados:</span>
                            <span class="ml-1 font-medium">{{ data.employees }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Horas:</span>
                            <span class="ml-1 font-medium">{{ data.total_hours }}h</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Extras:</span>
                            <span class="ml-1 font-medium text-green-600">{{ data.overtime_hours }}h</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Ausencias:</span>
                            <span class="ml-1 font-medium text-red-600">{{ data.absences }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Detalle por Empleado</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ausencias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Vacaciones</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Incapacidad</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="row in byEmployee" :key="row.employee?.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ row.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ row.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ row.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">
                            {{ row.days_worked }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="row.days_absent > 0 ? 'text-red-600 font-medium' : 'text-gray-500'">
                                {{ row.days_absent }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="row.days_late > 0 ? 'text-yellow-600 font-medium' : 'text-gray-500'">
                                {{ row.days_late }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">
                            {{ row.total_hours }}h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="row.overtime_hours > 0 ? 'text-green-600 font-medium' : 'text-gray-500'">
                                {{ row.overtime_hours }}h
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="row.vacation_days > 0 ? 'text-purple-600 font-medium' : 'text-gray-500'">
                                {{ row.vacation_days }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <span :class="row.sick_days > 0 ? 'text-pink-600 font-medium' : 'text-gray-500'">
                                {{ row.sick_days }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            No hay registros para este mes
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
