<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    from: String,
    to: String,
    vacaciones: { type: Array, default: () => [] },
    faltas: { type: Array, default: () => [] },
    retardos: { type: Array, default: () => [] },
    incapacidades: { type: Array, default: () => [] },
    finiquitos: { type: Array, default: () => [] },
    cumpleanos: { type: Array, default: () => [] },
});

const fromInput = ref(props.from);
const toInput = ref(props.to);

watch(() => [props.from, props.to], ([f, t]) => {
    fromInput.value = f;
    toInput.value = t;
});

const changeRange = () => {
    router.get(route('reports.resumen'), {
        from: fromInput.value || undefined,
        to: toInput.value || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const exportUrl = computed(() =>
    route('reports.resumen.export', { from: props.from, to: props.to }));

const fmt = (d) => {
    if (!d) return '';
    const [y, m, day] = d.split('-');
    return `${day}/${m}/${y}`;
};

const sections = computed(() => [
    { key: 'vacaciones', title: 'Vacaciones', rows: props.vacaciones, color: 'text-green-700' },
    { key: 'faltas', title: 'Faltas', rows: props.faltas, color: 'text-red-700' },
    { key: 'retardos', title: 'Faltas por retardo', rows: props.retardos, color: 'text-amber-700' },
    { key: 'incapacidades', title: 'Incapacidades', rows: props.incapacidades, color: 'text-blue-700' },
    { key: 'finiquitos', title: 'Finiquito', rows: props.finiquitos, color: 'text-gray-700' },
    { key: 'cumpleanos', title: 'Cumpleaños', rows: props.cumpleanos, color: 'text-pink-700' },
]);
</script>

<template>
    <Head title="Resumen semanal" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Resumen semanal
            </h2>
        </template>

        <div class="flex flex-wrap items-end justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Resumen semanal</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Vacaciones, faltas, faltas por retardo, incapacidades, finiquito y cumpleaños del rango, en una sola vista.
                </p>
            </div>
            <a
                :href="exportUrl"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium"
            >
                Exportar a Excel
            </a>
        </div>

        <!-- Range selector -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                    <input
                        v-model="fromInput"
                        type="date"
                        :max="toInput"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="changeRange"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                    <input
                        v-model="toInput"
                        type="date"
                        :min="fromInput"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="changeRange"
                    />
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500">Del {{ fmt(from) }} al {{ fmt(to) }}</p>
        </div>

        <!-- Sections -->
        <div class="space-y-6">
            <div v-for="section in sections" :key="section.key" class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold" :class="section.color">{{ section.title }}</h3>
                    <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                        {{ section.rows.length }}
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha</th>
                                <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Observaciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <tr v-for="(row, i) in section.rows" :key="i" class="hover:bg-gray-50">
                                <td class="px-6 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ row.name }}
                                    <span v-if="row.employee_number" class="ml-1 text-xs text-gray-400">{{ row.employee_number }}</span>
                                </td>
                                <td class="px-6 py-2 whitespace-nowrap text-sm text-gray-700">{{ row.date }}</td>
                                <td class="px-6 py-2 text-sm text-gray-600">{{ row.observaciones }}</td>
                            </tr>
                            <tr v-if="section.rows.length === 0">
                                <td colspan="3" class="px-6 py-6 text-center text-sm text-gray-400">
                                    Sin registros en este rango.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
