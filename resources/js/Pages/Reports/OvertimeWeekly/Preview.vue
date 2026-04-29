<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDate } from './format';
import BiesTable from './components/BiesTable.vue';
import CalidadTable from './components/CalidadTable.vue';
import CorteTable from './components/CorteTable.vue';
import DefaultTable from './components/DefaultTable.vue';
import DisenoTable from './components/DisenoTable.vue';

const props = defineProps({
    report: Object,
    layout: String,
});

const tableComponent = computed(() => {
    switch (props.layout) {
        case 'bies': return BiesTable;
        case 'calidad': return CalidadTable;
        case 'corte': return CorteTable;
        case 'diseno': return DisenoTable;
        default: return DefaultTable;
    }
});

const exportParams = computed(() => ({
    department_id: props.report.department.id,
    week_start: props.report.week_start,
}));

const pdfHref = computed(() => route('reports.overtime-weekly.export.pdf', exportParams.value));
const excelHref = computed(() => route('reports.overtime-weekly.export.excel', exportParams.value));
</script>

<template>
    <Head :title="`Tiempo Extra - ${report.department.name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Formato de Tiempo Extra - {{ report.department.name }}
            </h2>
        </template>

        <!-- Toolbar -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-3">
                <Link :href="route('reports.overtime-weekly.index')" class="text-pink-600 hover:text-pink-800 text-sm">
                    &larr; Cambiar semana / departamento
                </Link>
                <span class="text-sm text-gray-500">
                    Semana del <strong>{{ formatDate(report.week_start) }}</strong> al
                    <strong>{{ formatDate(report.week_end) }}</strong>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <a
                    :href="pdfHref"
                    class="inline-flex items-center px-3 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3" />
                    </svg>
                    Descargar PDF
                </a>
                <a
                    :href="excelHref"
                    class="inline-flex items-center px-3 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 text-sm"
                >
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6a2 2 0 012-2h2a2 2 0 012 2v6m-7 4h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Descargar Excel
                </a>
            </div>
        </div>

        <!-- Summary chips -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ report.totals.employee_count }}</p>
                <p class="text-xs text-gray-500">Empleados</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ report.totals.total_hours }}h</p>
                <p class="text-xs text-gray-500">Total Extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-amber-600">{{ report.totals.weekend_hours }}h</p>
                <p class="text-xs text-gray-500">Fin de Semana</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-purple-600">{{ report.totals.velada_count }}</p>
                <p class="text-xs text-gray-500">Veladas</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-pink-600">{{ report.totals.cena_count }}</p>
                <p class="text-xs text-gray-500">Cenas</p>
            </div>
        </div>

        <!-- Layout-specific table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <component :is="tableComponent" :report="report" />
        </div>
    </AppLayout>
</template>
