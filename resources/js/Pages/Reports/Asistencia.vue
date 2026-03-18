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
    router.get(route('reports.asistencia'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};
</script>

<template>
    <Head title="Reporte de Asistencia Perfecta" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte de Asistencia Perfecta</h2>
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
                <a :href="route('reports.export.asistencia', { start_date: dateRange.start, end_date: dateRange.end })"
                   class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                    Exportar Excel
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ summary.perfect_count }}</p>
                <p class="text-xs text-gray-500">Asistencia Perfecta</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_active }}</p>
                <p class="text-xs text-gray-500">Total Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ summary.percentage }}%</p>
                <p class="text-xs text-gray-500">Porcentaje</p>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias Trabajados</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas Totales</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="row in byEmployee" :key="row.employee?.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center">
                                    <span class="text-emerald-600 text-sm font-medium">{{ row.employee?.full_name?.charAt(0) || '?' }}</span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ row.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ row.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="px-2 py-1 rounded-full text-sm font-bold bg-emerald-100 text-emerald-800">
                                {{ row.days_worked }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 font-medium">
                            {{ row.total_hours }} hrs
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="3" class="px-6 py-12 text-center text-gray-500">No hay empleados con asistencia perfecta en este periodo</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
            <p class="text-sm text-emerald-800"><strong>Nota:</strong> Asistencia perfecta: sin faltas, retardos ni salidas tempranas en el periodo.</p>
        </div>
    </AppLayout>
</template>
