<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    departments: Array,
    defaultWeekStart: String,
});

const selectedDepartment = ref(props.departments[0]?.id ?? null);
const weekStart = ref(props.defaultWeekStart);

const formatRange = computed(() => {
    if (!weekStart.value) return '';
    const start = new Date(weekStart.value + 'T00:00:00');
    const dow = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - dow);
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    const fmt = (d) => d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
    return `${fmt(start)} al ${fmt(end)}`;
});

const changeWeek = (delta) => {
    const d = new Date(weekStart.value + 'T00:00:00');
    d.setDate(d.getDate() + delta * 7);
    weekStart.value = d.toISOString().split('T')[0];
};

const generate = () => {
    if (!selectedDepartment.value || !weekStart.value) return;
    router.get(route('reports.overtime-weekly.preview'), {
        department_id: selectedDepartment.value,
        week_start: weekStart.value,
    });
};
</script>

<template>
    <Head title="Tiempo Extra Semanal" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Formato de Tiempo Extra (Semanal)
            </h2>
        </template>

        <div class="mb-4">
            <Link :href="route('reports.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a reportes
            </Link>
        </div>

        <div class="bg-white rounded-lg shadow p-6 max-w-3xl">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Generar reporte</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                    <select
                        v-model="selectedDepartment"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Semana</label>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="changeWeek(-1)"
                            class="p-2 rounded hover:bg-gray-100"
                            aria-label="Semana anterior"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <input
                            v-model="weekStart"
                            type="date"
                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        />
                        <button
                            type="button"
                            @click="changeWeek(1)"
                            class="p-2 rounded hover:bg-gray-100"
                            aria-label="Semana siguiente"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Semana del {{ formatRange }}</p>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    @click="generate"
                    :disabled="!selectedDepartment || !weekStart"
                    class="inline-flex items-center px-4 py-2 bg-pink-600 text-white rounded-lg shadow hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    Generar reporte
                </button>
            </div>
        </div>

        <div class="mt-6 bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800 max-w-3xl">
            <p>
                Cada departamento tiene su propia plantilla de exportación.
                Departamentos con formato dedicado: <strong>Bies</strong>, <strong>Control de Calidad</strong>,
                <strong>Corte</strong>, <strong>Diseño</strong>. El resto usa un formato genérico.
            </p>
        </div>
    </AppLayout>
</template>
