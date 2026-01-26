<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    date: String,
    records: Array,
    summary: Object,
    byDepartment: Object,
});

const selectedDate = ref(props.date);

watch(selectedDate, (newDate) => {
    router.get(route('reports.daily'), { date: newDate }, {
        preserveState: true,
        replace: true,
    });
});

const statusColors = {
    present: 'bg-green-100 text-green-800',
    late: 'bg-yellow-100 text-yellow-800',
    absent: 'bg-red-100 text-red-800',
    partial: 'bg-orange-100 text-orange-800',
    vacation: 'bg-purple-100 text-purple-800',
    sick_leave: 'bg-pink-100 text-pink-800',
    holiday: 'bg-blue-100 text-blue-800',
};

const statusLabels = {
    present: 'Presente',
    late: 'Retardo',
    absent: 'Ausente',
    partial: 'Parcial',
    vacation: 'Vacaciones',
    sick_leave: 'Incapacidad',
    holiday: 'Festivo',
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
};

const changeDate = (delta) => {
    const d = new Date(selectedDate.value);
    d.setDate(d.getDate() + delta);
    selectedDate.value = d.toISOString().split('T')[0];
};
</script>

<template>
    <Head title="Reporte Diario" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte Diario de Asistencia
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Date Selector -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex items-center justify-between">
                <button @click="changeDate(-1)" class="p-2 hover:bg-gray-100 rounded">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div class="flex items-center space-x-4">
                    <input
                        v-model="selectedDate"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                    <span class="text-lg font-medium text-gray-800 capitalize">{{ formatDate(selectedDate) }}</span>
                </div>
                <div class="flex items-center space-x-2">
                    <a
                        :href="route('reports.export.daily', { date: selectedDate })"
                        class="inline-flex items-center px-3 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                    >
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Exportar CSV
                    </a>
                    <button @click="changeDate(1)" class="p-2 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total }}</p>
                <p class="text-xs text-gray-500">Total</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.present }}</p>
                <p class="text-xs text-gray-500">Presentes</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ summary.late }}</p>
                <p class="text-xs text-gray-500">Retardos</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.absent }}</p>
                <p class="text-xs text-gray-500">Ausentes</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-orange-600">{{ summary.partial }}</p>
                <p class="text-xs text-gray-500">Parciales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ summary.vacation }}</p>
                <p class="text-xs text-gray-500">Vacaciones</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-pink-600">{{ summary.sick_leave }}</p>
                <p class="text-xs text-gray-500">Incapacidad</p>
            </div>
        </div>

        <!-- By Department -->
        <div v-if="Object.keys(byDepartment).length > 0" class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Por Departamento</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="(data, dept) in byDepartment"
                    :key="dept"
                    class="bg-gray-50 rounded-lg p-4"
                >
                    <h4 class="font-medium text-gray-800">{{ dept }}</h4>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-gray-500">Presentes:</span>
                            <span class="ml-1 font-medium text-green-600">{{ data.present }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Ausentes:</span>
                            <span class="ml-1 font-medium text-red-600">{{ data.absent }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Horas:</span>
                            <span class="ml-1 font-medium">{{ data.total_hours }}h</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Extras:</span>
                            <span class="ml-1 font-medium text-blue-600">{{ data.overtime_hours }}h</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Records Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Departamento</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entrada</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Salida</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="record in records" :key="record.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-sm font-medium text-gray-900">{{ record.employee?.full_name }}</p>
                            <p class="text-xs text-gray-500">{{ record.employee?.employee_number }}</p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ record.employee?.department?.name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                            {{ record.check_in || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                            {{ record.check_out || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ record.worked_hours }}h
                            <span v-if="record.overtime_hours > 0" class="text-green-600 text-xs ml-1">
                                (+{{ record.overtime_hours }}h)
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[record.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[record.status] }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="records.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            No hay registros para esta fecha
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
