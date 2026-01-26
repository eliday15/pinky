<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    startDate: String,
    endDate: String,
    incidents: Array,
    byType: Array,
    byDepartment: Array,
    byStatus: Object,
    summary: Object,
});

const dateRange = ref({ start: props.startDate, end: props.endDate });

const applyFilter = () => {
    router.get(route('reports.incidents'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

const formatDate = (date) => new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' });

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
};

const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobada',
    rejected: 'Rechazada',
};
</script>

<template>
    <Head title="Reporte de Incidencias" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte de Incidencias</h2>
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

        <!-- Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_incidents }}</p>
                <p class="text-xs text-gray-500">Total</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_days }}</p>
                <p class="text-xs text-gray-500">Dias Totales</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ summary.pending_count }}</p>
                <p class="text-xs text-gray-500">Pendientes</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.approved_count }}</p>
                <p class="text-xs text-gray-500">Aprobadas</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ summary.rejected_count }}</p>
                <p class="text-xs text-gray-500">Rechazadas</p>
            </div>
        </div>

        <!-- By Type and Department -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Por Tipo</h3>
                <div class="space-y-3">
                    <div v-for="item in byType" :key="item.type" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-800">{{ item.type }}</p>
                            <p class="text-xs text-gray-500">{{ item.total_days }} dias</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded">{{ item.approved }}</span>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded">{{ item.pending }}</span>
                            <span class="px-2 py-1 bg-gray-200 text-gray-800 text-sm font-bold rounded">{{ item.count }}</span>
                        </div>
                    </div>
                    <p v-if="byType.length === 0" class="text-center text-gray-500 py-4">Sin datos</p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Por Departamento</h3>
                <div class="space-y-3">
                    <div v-for="item in byDepartment" :key="item.department" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-800">{{ item.department }}</p>
                            <p class="text-xs text-gray-500">{{ item.total_days }} dias</p>
                        </div>
                        <span class="px-3 py-1 bg-pink-100 text-pink-800 font-bold rounded">{{ item.count }}</span>
                    </div>
                    <p v-if="byDepartment.length === 0" class="text-center text-gray-500 py-4">Sin datos</p>
                </div>
            </div>
        </div>

        <!-- Incidents List -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">Detalle de Incidencias</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Periodo</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="incident in incidents.slice(0, 20)" :key="incident.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <p class="text-sm font-medium text-gray-900">{{ incident.employee?.full_name }}</p>
                            <p class="text-xs text-gray-500">{{ incident.employee?.department?.name }}</p>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ incident.incident_type?.name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ formatDate(incident.start_date) }} - {{ formatDate(incident.end_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">{{ incident.days_count }}</td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[incident.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[incident.status] }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="incidents.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">No hay incidencias</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="incidents.length > 20" class="px-6 py-4 bg-gray-50 text-center text-sm text-gray-500">
                Mostrando 20 de {{ incidents.length }} incidencias
            </div>
        </div>
    </AppLayout>
</template>
