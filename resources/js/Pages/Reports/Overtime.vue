<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';

const props = defineProps({
    startDate: String,
    endDate: String,
    byEmployee: Array,
    summary: Object,
    includesAmounts: Boolean,
});

const dateRange = ref({
    start: props.startDate,
    end: props.endDate,
});

const selectedMonth = ref(props.startDate.slice(0, 7));
const selectMonth = () => {
    if (!selectedMonth.value) return;
    const [year, month] = selectedMonth.value.split('-').map(Number);
    dateRange.value = {
        start: `${selectedMonth.value}-01`,
        end: `${selectedMonth.value}-${new Date(year, month, 0).getDate()}`,
    };
    applyFilter();
};
const exportUrl = () => route('reports.overtime', {
    start_date: props.startDate, end_date: props.endDate, export: 'xlsx',
});

const applyFilter = () => {
    router.get(route('reports.overtime'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, {
        preserveState: true,
        replace: true,
    });
};

const formatDate = (date) => fmtDate(date, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount || 0);
};
</script>

<template>
    <Head title="Reporte de Extras Autorizados" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Reporte de Extras Autorizados
            </h2>
        </template>

        <div class="mb-6">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <!-- Date Range -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mes completo</label>
                    <input v-model="selectedMonth" type="month" @change="selectMonth" class="rounded-lg border-gray-300" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                    <input
                        v-model="dateRange.start"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                    <input
                        v-model="dateRange.end"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <button
                    @click="applyFilter"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                >
                    Aplicar
                </button>
            </div>
            <a v-if="includesAmounts" :href="exportUrl()" class="inline-block mt-3 text-pink-600 font-medium">Descargar Excel del resultado</a>
            <p class="mt-2 text-sm text-gray-500">
                Mostrando del {{ formatDate(startDate) }} al {{ formatDate(endDate) }}
            </p>
        </div>

        <p class="mb-4 text-sm text-gray-600">Acumulado de conceptos aprobados o pagados en las fechas seleccionadas, disponible antes del cierre. Incluye horas extra, veladas, cenas, comidas, fines de semana y otros conceptos autorizados. No incluye cálculo del sueldo base ni asignaciones recurrentes; los importes se estiman con las tarifas configuradas actualmente.</p>

        <p v-if="includesAmounts && summary.estimate_incomplete" class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">El total está incompleto: hay cantidades autorizadas sin importe calculable. Revisa las tarifas y conceptos asignados antes de pagar.</p>
        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ summary.total_employees }}</p>
                <p class="text-xs text-gray-500">Empleados con Extras</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ summary.total_overtime_hours }}h</p>
                <p class="text-xs text-gray-500">Total Horas Extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-blue-600">{{ summary.total_days_with_overtime }}</p>
                <p class="text-xs text-gray-500">Dias con Extras</p>
            </div>
            <div v-if="includesAmounts" class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-pink-600">{{ formatCurrency(summary.total_estimated_cost) }}</p>
                <p class="text-xs text-gray-500">Total Autorizado Estimado</p>
            </div>
        </div>

        <div v-if="summary.concepts?.length" class="bg-white rounded-lg shadow p-4 mb-6">
            <h3 class="font-semibold mb-3">Total por concepto</h3>
            <div class="flex flex-wrap gap-6">
                <div v-for="concept in summary.concepts" :key="concept.code + concept.name">
                    <p class="text-sm text-gray-600">{{ concept.name }} ({{ concept.code }})</p>
                    <p v-if="includesAmounts" class="font-semibold">{{ formatCurrency(concept.amount) }}</p>
                    <p class="text-xs text-gray-500">{{ concept.hours }} h · {{ concept.quantity }} unidades</p>
                    <p v-if="concept.missing_rate" class="text-xs text-amber-700">Hay autorizaciones sin tarifa configurada</p>
                </div>
            </div>
        </div>
        <!-- Employee Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Dias con Extra</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas Extra</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Horas Autorizadas</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Conceptos autorizados</th>
                        <th v-if="includesAmounts" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Estimado</th>
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
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ row.days_with_overtime }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-green-600">
                            {{ row.total_overtime }}h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900">
                            {{ row.total_authorized }}h
                        </td>
                        <td class="px-6 py-4 text-sm min-w-72">
                            <div v-for="concept in row.concepts" :key="concept.code + concept.name" class="mb-2">
                                <span>{{ concept.name }} ({{ concept.code }}): </span>
                                <strong v-if="includesAmounts">{{ formatCurrency(concept.amount) }}</strong>
                                <p class="text-xs text-gray-500">{{ concept.hours }} h · {{ concept.quantity }} unidades</p>
                                <p v-if="concept.missing_rate" class="text-xs text-amber-700">Sin tarifa configurada</p>
                            </div>
                        </td>
                        <td v-if="includesAmounts" class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                            {{ formatCurrency(row.estimated_cost) }}
                            <p v-if="row.estimate_incomplete" class="text-xs text-amber-700">Importe incompleto</p>
                        </td>
                    </tr>
                    <tr v-if="byEmployee.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de extras para este periodo
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="byEmployee.length > 0" class="bg-gray-50 border-t-2 border-gray-300">
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 uppercase">
                            Total
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            {{ summary.total_days_with_overtime }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-green-600">
                            {{ summary.total_overtime_hours }}h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            {{ summary.total_authorized_hours }}h
                        </td>
                        <td></td>
                        <td v-if="includesAmounts" class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            {{ formatCurrency(summary.total_estimated_cost) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
