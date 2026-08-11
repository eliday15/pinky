<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, provide, ref } from 'vue';
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

// Default to approved-only view; toggle reveals pending markers in cells.
const showPending = ref(false);
provide('showPending', showPending);

// Observaciones visibles por defecto; al ocultarlas el reporte impreso ocupa
// menos hojas (Dani 2026-07-02). El toggle controla la vista y también viaja a
// la descarga (PDF/Excel) vía show_observations.
const showObservations = ref(true);
provide('showObservations', showObservations);

/** Drop rows that have nothing to report so the table stays readable.
 *  "Nothing" = zero approved OT/finde/velada/cena/comida, ningún "otro
 *  concepto" (p. ej. un bono o cantidad fija semanal — antes esas filas
 *  desaparecían de la hoja) y (si no se muestran pendientes) cero pendientes. */
const visibleReport = computed(() => {
    const r = props.report;
    if (!r) return r;
    const rows = r.rows.filter(row => {
        const approved = (row.totals?.total_hours || 0) + (row.totals?.weekend_hours || 0)
            + (row.totals?.velada_count || 0) + (row.totals?.cena_count || 0)
            + (row.totals?.comida_count || 0)
            + (row.extra_concepts?.length || 0);
        const pending = showPending.value ? (row.totals?.pending_hours || 0) : 0;
        return approved > 0 || pending > 0;
    });
    return { ...r, rows };
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
    end_date: props.report.week_end,
    show_observations: showObservations.value ? 1 : 0,
    // Que el PDF/Excel exporte lo MISMO que se está viendo en pantalla.
    include_pending: props.report.includes_pending ? 1 : 0,
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
                    Periodo del <strong>{{ formatDate(report.week_start) }}</strong> al
                    <strong>{{ formatDate(report.week_end) }}</strong>
                    <span
                        v-if="report.includes_pending"
                        class="ml-2 px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 align-middle"
                        title="Los totales suman también capturas aún no aprobadas. El reporte oficial es sin pendientes."
                    >
                        Incluye pendientes de aprobar
                    </span>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <label class="inline-flex items-center gap-1 px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" v-model="showPending" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                    Mostrar pendientes por aprobar
                </label>
                <label class="inline-flex items-center gap-1 px-3 py-2 bg-white border border-gray-300 rounded-lg text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" v-model="showObservations" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                    Mostrar observaciones
                </label>
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
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4">
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ visibleReport.rows.length }}<span class="text-base text-gray-400"> / {{ report.totals.employee_count }}</span></p>
                <p class="text-xs text-gray-500">Empleados con OT</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-emerald-600">{{ report.totals.total_hours }}h <span class="text-base">✓</span></p>
                <p class="text-xs text-gray-500">Aprobadas</p>
            </div>
            <div v-if="showPending" class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-amber-600">+{{ report.totals.pending_hours || 0 }}h</p>
                <p class="text-xs text-gray-500">Pendientes por aprobar</p>
            </div>
            <div class="bg-white rounded-lg shadow p-3 text-center">
                <p class="text-2xl font-bold text-amber-600">{{ report.weekend_unit_hours ? report.totals.weekend_units : report.totals.weekend_hours + 'h' }}</p>
                <p class="text-xs text-gray-500">{{ report.weekend_unit_hours ? 'Fines de Semana' : 'Fin de Semana' }}</p>
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
            <component :is="tableComponent" :report="visibleReport" />
        </div>
    </AppLayout>
</template>
