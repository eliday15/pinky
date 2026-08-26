<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';
import { periodTypeInfo } from '@/utils/payrollPeriodType';

const props = defineProps({
    suggestedDates: Object,
    // Día en que arranca la siguiente semana (el día después del último periodo
    // base de la general). Rellena el rango de semana del alta MENSUAL.
    nextWeekStart: {
        type: String,
        default: null,
    },
    // Nombres de departamentos con nómina propia (p. ej. ["Taller"]). Al crear,
    // el sistema genera la General MÁS una por cada uno, de un jalón.
    separatePayrollDepartments: {
        type: Array,
        default: () => [],
    },
});

const typeInfo = computed(() => periodTypeInfo(form.type));

// Etiquetas de las nóminas que se generarán en un solo alta.
const payrollsToGenerate = computed(() => ['General', ...props.separatePayrollDepartments]);

const addDaysToDate = (dateStr, days) => {
    const d = new Date(`${dateStr}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
};

const today = () => {
    const now = new Date();
    return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()))
        .toISOString()
        .slice(0, 10);
};

// Lunes de la semana que contiene la fecha dada. El sueldo base semanal SIEMPRE
// corre de lunes a domingo (semanas contiguas), para que la regla de "semana
// completa" no se rompa entre periodos.
const mondayOf = (dateStr) => {
    const d = new Date(`${dateStr}T00:00:00Z`);
    const dow = d.getUTCDay(); // 0=Dom, 1=Lun, ... 6=Sáb
    const diff = dow === 0 ? -6 : 1 - dow; // retrocede al lunes
    d.setUTCDate(d.getUTCDate() + diff);
    return d.toISOString().slice(0, 10);
};

// Semanal es el flujo principal: arranca en el lunes de la semana actual y
// cubre lunes→domingo; el pago se propone al día siguiente (lunes siguiente).
const form = useForm({
    name: '',
    type: 'weekly',
    start_date: mondayOf(today()),
    end_date: addDaysToDate(mondayOf(today()), 6),
    payment_date: addDaysToDate(mondayOf(today()), 7),
});

const periodDays = computed(() => {
    if (!form.start_date || !form.end_date) return 0;
    const start = new Date(form.start_date);
    const end = new Date(form.end_date);
    return Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
});

// Semanal SIEMPRE termina en domingo: el fin se ancla solo al domingo de la
// semana del inicio. El inicio NO se fuerza al lunes: una semana corta de
// transición (p. ej. la anterior terminó lunes 6 jul → esta va 7–12) es
// válida; el cálculo ya paga la base completa sin doble pago y a la semana
// siguiente todo vuelve a lunes→domingo.
watch([() => form.start_date, () => form.type], () => {
    if (form.type === 'weekly' && form.start_date) {
        form.end_date = addDaysToDate(mondayOf(form.start_date), 6);
    }
});

// El pago de una semanal se propone al día siguiente del fin (igual que las
// semanas reales, p. ej. 19–25 may pagada el 26 may).
watch(() => form.end_date, () => {
    if (form.type === 'weekly' && form.end_date) {
        form.payment_date = addDaysToDate(form.end_date, 1);
    }
});

// La semana cuyo SUELDO se paga junto con este mes: arranca donde terminó la
// semana anterior y cierra con el mes. Se deduce de las fechas (el backend hace
// el mismo cálculo, por alcance); aquí solo se muestra para que se vea qué va a
// salir antes de generar.
const weekPaidWithMonth = computed(() => {
    if (form.type !== 'monthly' || !form.end_date) return null;

    const sevenDayStart = addDaysToDate(form.end_date, -6);
    let start = props.nextWeekStart || sevenDayStart;
    if (start > form.end_date || start < sevenDayStart) {
        start = sevenDayStart;
    }

    return { start, end: form.end_date };
});

const formatDateForName = (date) => fmtDate(date, {
    day: 'numeric',
    month: 'short',
});

const generateName = () => {
    if (form.start_date && form.end_date) {
        const typeLabel = form.type === 'biweekly' ? 'Quincena' : form.type === 'weekly' ? 'Semana' : 'Mes';
        form.name = `${typeLabel} ${formatDateForName(form.start_date)} - ${formatDateForName(form.end_date)}`;
    }
};

const submit = () => {
    if (!form.name) {
        generateName();
    }
    form.post(route('payroll.store'));
};
</script>

<template>
    <Head title="Nuevo Periodo de Nomina" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Periodo de Nomina
            </h2>
        </template>

        <div class="max-w-2xl">
            <div class="mb-6">
                <Link
                    :href="route('payroll.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a nominas
                </Link>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Period Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Tipo de Periodo <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.type"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.type }"
                    >
                        <option value="weekly">Semanal — sueldo base (7 dias)</option>
                        <option value="monthly">Mensual — extras (se unen a la semana que se paga igual)</option>
                        <option value="biweekly">Quincenal — todo junto (modo anterior)</option>
                    </select>
                    <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">{{ form.errors.type }}</p>

                    <!-- What this period type pays -->
                    <div class="mt-3 border rounded-lg p-4" :class="typeInfo.tone.box">
                        <p class="text-sm font-semibold" :class="typeInfo.tone.title">{{ typeInfo.title }}</p>
                        <p class="mt-1 text-sm" :class="typeInfo.tone.text">{{ typeInfo.description }}</p>
                        <ul class="mt-2 space-y-1">
                            <li
                                v-for="(item, idx) in typeInfo.pays"
                                :key="idx"
                                class="flex items-center text-sm"
                                :class="typeInfo.tone.text"
                            >
                                <span class="w-1.5 h-1.5 rounded-full mr-2" :class="typeInfo.tone.dot"></span>
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Aviso: el mensual se paga JUNTO con la semana (un solo pago) -->
                <div v-if="form.type === 'monthly'" class="bg-pink-50 border border-pink-200 rounded-lg p-4">
                    <p class="text-sm font-semibold text-pink-800">Un solo pago: el mes y la semana juntos</p>
                    <p class="mt-1 text-sm text-pink-700">
                        La nómina <span class="font-semibold">General</span> sale con todos los cargos del mes
                        <span v-if="form.start_date && form.end_date" class="font-semibold">({{ formatDateForName(form.start_date) }} - {{ formatDateForName(form.end_date) }})</span>
                        <span v-if="weekPaidWithMonth">
                            más el sueldo de la semana
                            <span class="font-semibold">{{ formatDateForName(weekPaidWithMonth.start) }} - {{ formatDateForName(weekPaidWithMonth.end) }}</span>
                        </span>, en el mismo recibo.
                        <span v-if="separatePayrollDepartments.length">
                            {{ separatePayrollDepartments.join(', ') }} no lleva mensual: sale con esa semana nada más.
                        </span>
                    </p>
                    <p class="mt-2 text-xs text-pink-600">
                        La semana sale sola de las fechas: arranca donde terminó la anterior y cierra con el mes.
                        Si esa semana ya está generada, los cargos del mes se le agregan a ella.
                    </p>
                </div>

                <!-- Aviso: se generan todas las nóminas de un jalón -->
                <div v-if="separatePayrollDepartments.length && form.type !== 'monthly'" class="bg-indigo-50 border border-indigo-200 rounded-lg p-4">
                    <p class="text-sm font-semibold text-indigo-800">
                        Se generarán {{ payrollsToGenerate.length }} nóminas de un jalón
                    </p>
                    <p class="mt-1 text-sm text-indigo-700">
                        Con estas fechas se crean y calculan:
                        <span class="font-semibold">{{ payrollsToGenerate.join(', ') }}</span>.
                        Los departamentos con nómina propia (p. ej. Taller) salen de la General y van en la suya.
                    </p>
                </div>

                <!-- Date Range -->
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Fecha Inicio <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.start_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.start_date }"
                        />
                        <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">{{ form.errors.start_date }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Fecha Fin <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.end_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.end_date }"
                        />
                        <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">{{ form.errors.end_date }}</p>
                    </div>
                </div>

                <!-- Period Days Info -->
                <div v-if="periodDays > 0" class="flex items-center bg-gray-50 rounded-lg p-4">
                    <svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="text-sm text-gray-600">
                        Este periodo abarca <span class="font-medium text-gray-900">{{ periodDays }} dias</span>
                        <span v-if="form.type === 'weekly'" class="text-gray-500"> — la semana termina en <span class="font-medium">domingo</span> (el fin se ajusta solo); puede iniciar a media semana si la anterior terminó después del domingo</span>
                    </span>
                </div>

                <!-- Payment Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Fecha de Pago <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.payment_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.payment_date }"
                    />
                    <p class="mt-1 text-xs text-gray-500">Fecha en que se pagara la nomina</p>
                    <p v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">{{ form.errors.payment_date }}</p>
                </div>

                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nombre del Periodo
                    </label>
                    <div class="flex space-x-2">
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="Ej: Quincena 1-15 Dic"
                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.name }"
                        />
                        <button
                            type="button"
                            @click="generateName"
                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                        >
                            Generar
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Dejalo vacio para generar automaticamente</p>
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <!-- Info Box -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex">
                        <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3 text-sm text-blue-700">
                            <p class="font-medium">Al crear</p>
                            <p>La nomina se genera Y se calcula automaticamente para todos los empleados activos; luego solo la revisas y apruebas.</p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-4 border-t">
                    <Link
                        :href="route('payroll.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Generando...' : 'Generar Nomina' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
