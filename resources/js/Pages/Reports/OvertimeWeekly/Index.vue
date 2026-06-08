<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    departments: Array,
    defaultWeekStart: String,
});

const selectedDepartment = ref(props.departments[0]?.id ?? null);

// El periodo se elige de qué día a qué día. Por defecto arranca en la semana
// actual (lun–dom), pero el usuario puede mover libremente inicio y fin.
const addDays = (dateStr, days) => {
    const d = new Date(dateStr + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
};

const startDate = ref(props.defaultWeekStart);
const endDate = ref(addDays(props.defaultWeekStart, 6));

const rangeValid = computed(() => {
    if (!startDate.value || !endDate.value) return false;
    return startDate.value <= endDate.value;
});

const dayCount = computed(() => {
    if (!rangeValid.value) return 0;
    const s = new Date(startDate.value + 'T00:00:00');
    const e = new Date(endDate.value + 'T00:00:00');
    return Math.round((e - s) / 86400000) + 1;
});

const formatRange = computed(() => {
    if (!rangeValid.value) return '';
    const fmt = (str) =>
        new Date(str + 'T00:00:00').toLocaleDateString('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    return `${fmt(startDate.value)} al ${fmt(endDate.value)}`;
});

// Salto rápido: mueve TODO el rango una semana (mantiene su duración).
const shiftWeek = (delta) => {
    startDate.value = addDays(startDate.value, delta * 7);
    endDate.value = addDays(endDate.value, delta * 7);
};

// Atajo: encuadra el rango a la semana lun–dom que contiene la fecha inicio.
const snapToWeek = () => {
    const d = new Date(startDate.value + 'T00:00:00');
    const dow = (d.getDay() + 6) % 7; // 0 = lunes
    const monday = addDays(startDate.value, -dow);
    startDate.value = monday;
    endDate.value = addDays(monday, 6);
};

const generate = () => {
    if (!selectedDepartment.value || !rangeValid.value) return;
    router.get(route('reports.overtime-weekly.preview'), {
        department_id: selectedDepartment.value,
        week_start: startDate.value,
        end_date: endDate.value,
    });
};
</script>

<template>
    <Head title="Tiempo Extra Semanal" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Formato de Tiempo Extra
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Periodo</label>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="shiftWeek(-1)"
                            class="p-2 rounded hover:bg-gray-100"
                            aria-label="Semana anterior"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <div class="flex-1 grid grid-cols-2 gap-2">
                            <div>
                                <span class="block text-xs text-gray-500 mb-1">Desde</span>
                                <input
                                    v-model="startDate"
                                    type="date"
                                    :max="endDate"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                />
                            </div>
                            <div>
                                <span class="block text-xs text-gray-500 mb-1">Hasta</span>
                                <input
                                    v-model="endDate"
                                    type="date"
                                    :min="startDate"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                />
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="shiftWeek(1)"
                            class="p-2 rounded hover:bg-gray-100"
                            aria-label="Semana siguiente"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                    <div class="flex items-center justify-between mt-1">
                        <p v-if="rangeValid" class="text-xs text-gray-500">
                            Del {{ formatRange }} ({{ dayCount }} {{ dayCount === 1 ? 'día' : 'días' }})
                        </p>
                        <p v-else class="text-xs text-red-600">La fecha "Desde" debe ser anterior o igual a "Hasta".</p>
                        <button type="button" @click="snapToWeek" class="text-xs text-pink-600 hover:text-pink-800">
                            Ajustar a semana (lun–dom)
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button
                    type="button"
                    @click="generate"
                    :disabled="!selectedDepartment || !rangeValid"
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
