<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    report: Object,      // empresa => seccion => filas ([col1, col2, col3])
    sections: Array,     // [{ key, title, headers }]
    empresas: Array,     // [{ key, label }]
    weekLabel: String,
    filters: Object,     // { start_date, end_date }
});

const dateRange = ref({ start: props.filters.start_date, end: props.filters.end_date });
const activeEmpresa = ref(props.empresas[0]?.key ?? 'VP');

const applyFilter = () => {
    router.get(route('reports.accountant'), {
        start_date: dateRange.value.start,
        end_date: dateRange.value.end,
    }, { preserveState: true, replace: true });
};

// Total de filas de una empresa (para la insignia de cada pestaña).
const empresaCount = (key) => {
    const sections = props.report[key] ?? {};
    return Object.values(sections).reduce((total, rows) => total + rows.length, 0);
};

const activeSections = computed(() => props.report[activeEmpresa.value] ?? {});
</script>

<template>
    <Head title="Reporte al Contador" />
    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Reporte al Contador</h2>
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
                <a :href="route('reports.accountant.export', { start_date: dateRange.start, end_date: dateRange.end })"
                   class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                    Exportar Excel
                </a>
            </div>
            <p class="mt-3 text-sm font-semibold uppercase tracking-wide text-gray-600">{{ weekLabel }}</p>
        </div>

        <!-- Pestañas por empresa (una hoja de Excel por empresa) -->
        <div class="mb-4 flex flex-wrap gap-2">
            <button
                v-for="empresa in empresas"
                :key="empresa.key"
                @click="activeEmpresa = empresa.key"
                :class="[
                    'px-4 py-2 rounded-lg text-sm font-medium border transition',
                    activeEmpresa === empresa.key
                        ? 'bg-pink-600 text-white border-pink-600'
                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50',
                ]"
            >
                {{ empresa.label }}
                <span
                    :class="[
                        'ml-2 inline-flex items-center justify-center rounded-full px-2 text-xs',
                        activeEmpresa === empresa.key ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-600',
                    ]"
                >{{ empresaCount(empresa.key) }}</span>
            </button>
        </div>

        <!-- Secciones de la empresa activa -->
        <div class="space-y-6">
            <div v-for="section in sections" :key="section.key" class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-gray-700">{{ section.title }}</h3>
                    <span class="text-xs text-gray-400">{{ (activeSections[section.key] || []).length }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-white">
                            <tr>
                                <th
                                    v-for="(header, i) in section.headers"
                                    :key="i"
                                    class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase"
                                >{{ header }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="(activeSections[section.key] || []).length === 0">
                                <td :colspan="section.headers.length" class="px-6 py-3 text-sm text-gray-400 italic">Sin registros</td>
                            </tr>
                            <tr v-for="(row, r) in (activeSections[section.key] || [])" :key="r" class="hover:bg-gray-50">
                                <td
                                    v-for="(cell, c) in row"
                                    :key="c"
                                    class="px-6 py-2 text-sm"
                                    :class="c === 0 ? 'text-gray-900 font-medium' : 'text-gray-600'"
                                >{{ cell || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
