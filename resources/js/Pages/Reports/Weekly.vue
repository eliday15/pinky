<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    startDate: String,
    endDate: String,
    byEmployee: Array,
    summary: Object,
});

const weekStart = ref(props.startDate);

watch(weekStart, (newDate) => {
    router.get(route('reports.weekly'), { start_date: newDate }, {
        preserveState: true,
        replace: true,
    });
});

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
    });
};

const changeWeek = (delta) => {
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + (delta * 7));
    weekStart.value = d.toISOString().split('T')[0];
};
</script>

<template>
    <Head title="Reporte Semanal" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte Semanal de Asistencia
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Week Selector -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex items-center justify-between">
                <button @click="changeWeek(-1)" class="p-2 hover:bg-gray-100 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div class="text-center">
                    <p class="text-lg font-medium text-gray-800">
                        Semana del {{ formatDate(startDate) }} al {{ formatDate(endDate) }}
                    </p>
                    <input
                        v-model="weekStart"
                        type="date"
                        class="mt-2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <button @click="changeWeek(1)" class="p-2 hover:bg-gray-100 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_hours?.toFixed(1) }}h</p>
                <p class="text-xs text-gray-500">Horas Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_overtime?.toFixed(1) }}h</p>
                <p class="text-xs text-gray-500">Horas Extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.total_absences }}</p>
                <p class="text-xs text-gray-500">Ausencias</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ summary.total_late }}</p>
                <p class="text-xs text-gray-500">Retardos</p>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias Trabajados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Ausencias</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Retardos</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Extras</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Min. Retardo</th>
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
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-500">
                            {{ row.late_minutes }}
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No hay registros para esta semana
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
