<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { todayLocal } from '@/utils/date';

const props = defineProps({
    employees: Array,
    selectedEmployee: [Number, String],
    types: Array,
    prefill: { type: Object, default: null },
});

const today = todayLocal();
const initialDate = props.prefill?.date || today;
const initialStartTime = props.prefill?.start_time || '08:00';
const initialEndTime = props.prefill?.end_time || '16:00';
const startDatetime = ref(`${initialDate}T${initialStartTime}`);
const endDatetime = ref(`${initialDate}T${initialEndTime}`);
const startDate = ref(initialDate);
const endDate = ref(initialDate);

const form = useForm({
    employee_id: props.prefill?.employee_id || props.selectedEmployee || '',
    type: props.prefill?.type || '',
    compensation_type_id: props.prefill?.compensation_type_id || null,
    date: initialDate,
    start_time: props.prefill?.start_time || '',
    end_time: props.prefill?.end_time || '',
    hours: props.prefill?.hours || '',
    reason: props.prefill?.reason || '',
    anomaly_id: props.prefill?.anomaly_id || null,
});

/** The application_mode of the currently selected compensation type. */
const selectedApplicationMode = computed(() => {
    if (!form.compensation_type_id) return null;
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.application_mode || null;
});

/** Human-readable label of the selected type. */
const selectedTypeLabel = computed(() => {
    if (!form.compensation_type_id) return '';
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.label || '';
});

/** Card title for the date/time step, adapted to the selected type's input mode. */
const dateCardTitle = computed(() => {
    switch (selectedApplicationMode.value) {
        case 'per_hour': return 'Fecha y Horario';
        case 'per_day': return 'Rango de Fechas';
        case 'one_time': return 'Fecha y Cantidad';
        default: return 'Fecha y Horario';
    }
});

/** Auto-calculate hours when datetimes change (per_hour mode). */
watch([startDatetime, endDatetime], ([start, end]) => {
    if (start && end && selectedApplicationMode.value === 'per_hour') {
        const s = new Date(start);
        const e = new Date(end);
        if (e > s) {
            form.hours = ((e - s) / (1000 * 60 * 60)).toFixed(2);
        }
        form.date = start.split('T')[0];
        form.start_time = start.split('T')[1] || '';
        form.end_time = end.split('T')[1] || '';
    }
});

/** Sync date fields for per_day mode. */
watch([startDate, endDate], ([start, end]) => {
    if (start && selectedApplicationMode.value === 'per_day') {
        form.date = start;
        form.start_time = '';
        form.end_time = '';
        if (start && end) {
            const s = new Date(start);
            const e = new Date(end);
            const days = Math.ceil((e - s) / (1000 * 60 * 60 * 24)) + 1;
            form.hours = days > 0 ? days : '';
        }
    }
});

/** Sync date for one_time mode. */
watch(startDate, (val) => {
    if (selectedApplicationMode.value === 'one_time') {
        form.date = val;
        form.start_time = '';
        form.end_time = '';
    }
});

const submit = () => {
    form.post(route('authorizations.store'));
};

/* ----- Live suggestion from schedule + attendance ----- */
const suggestion = ref(null); // { found, start_time, end_time, hours, summary, message }
const suggestionLoading = ref(false);
let suggestTimer = null;

const canSuggest = computed(() => {
    return form.employee_id
        && form.date
        && (form.type === 'overtime' || form.type === 'night_shift')
        && selectedApplicationMode.value === 'per_hour';
});

const fetchSuggestion = async () => {
    if (!canSuggest.value) {
        suggestion.value = null;
        return;
    }
    suggestionLoading.value = true;
    try {
        const { data } = await axios.get(route('authorizations.suggest'), {
            params: {
                employee_id: form.employee_id,
                date: form.date,
                type: form.type,
            },
        });
        suggestion.value = data;
    } catch {
        suggestion.value = null;
    } finally {
        suggestionLoading.value = false;
    }
};

watch(
    () => [form.employee_id, form.date, form.type],
    () => {
        suggestion.value = null;
        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(fetchSuggestion, 300);
    },
    { immediate: true },
);

const applySuggestion = () => {
    if (!suggestion.value?.found) return;
    const date = form.date;
    startDatetime.value = `${date}T${suggestion.value.start_time}`;
    endDatetime.value = `${date}T${suggestion.value.end_time}`;
    form.start_time = suggestion.value.start_time;
    form.end_time = suggestion.value.end_time;
    form.hours = suggestion.value.hours;
};

/** Active compensation type IDs for the selected employee. */
const selectedEmployeeData = computed(() => {
    if (!form.employee_id) return null;
    return props.employees.find(e => e.id == form.employee_id);
});

/** Group types for optgroup display, filtered by employee's active compensation types. */
const compensationTypes = computed(() => {
    const all = props.types.filter(t => t.group === 'compensation');
    const ids = selectedEmployeeData.value?.active_compensation_type_ids;
    if (!ids) return all;
    return all.filter(t => ids.includes(t.compensation_type_id));
});
/**
 * Build a unique option value for each type entry.
 */
const optionValue = (type) => {
    return `comp_${type.compensation_type_id}`;
};

/** Currently selected option value (derived from form state). */
const selectedOptionValue = computed(() => {
    if (form.compensation_type_id) return `comp_${form.compensation_type_id}`;
    return form.type;
});

/** When user selects a type, parse and set both type and compensation_type_id. */
const onTypeChange = (event) => {
    const raw = event.target.value;
    if (raw.startsWith('comp_')) {
        const compId = parseInt(raw.replace('comp_', ''), 10);
        const matched = props.types.find(t => t.compensation_type_id === compId);
        form.type = matched?.value || '';
        form.compensation_type_id = compId;
    } else {
        form.type = '';
        form.compensation_type_id = null;
    }
};

/** Reset type selection when employee changes and selected type is no longer available. */
watch(() => form.employee_id, () => {
    if (form.compensation_type_id) {
        const ids = selectedEmployeeData.value?.active_compensation_type_ids;
        if (ids && !ids.includes(form.compensation_type_id)) {
            form.type = '';
            form.compensation_type_id = null;
        }
    }
});

const typeDescriptions = {
    overtime: 'Horas adicionales trabajadas fuera del horario normal',
    night_shift: 'Turno nocturno o velada completa',
    holiday_worked: 'Trabajo realizado en dia festivo oficial',
    special: 'Autorizacion especial que no encaja en otras categorias',
};
</script>

<template>
    <Head title="Nueva Autorizacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nueva Autorizacion
            </h2>
        </template>

        <div class="max-w-3xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Anomaly suggestion banner -->
                <div v-if="prefill?.anomaly_id" class="bg-orange-50 border-l-4 border-orange-400 rounded-lg p-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-orange-500 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-orange-800">Sugerencia basada en anomalia #{{ prefill.anomaly_id }}</h4>
                            <p class="text-sm text-orange-700 mt-1">{{ prefill.anomaly_summary }}</p>
                            <p class="text-xs text-orange-600 mt-2">Los horarios fueron pre-llenados desde los registros biometricos. Ajustalos si es necesario antes de guardar.</p>
                        </div>
                    </div>
                </div>

                <!-- Employee & Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Empleado *
                            </label>
                            <SearchableSelect
                                v-model="form.employee_id"
                                :options="employees"
                                value-key="id"
                                label-key="full_name"
                                secondary-key="employee_number"
                                placeholder="Buscar empleado..."
                                :has-error="!!form.errors.employee_id"
                            />
                            <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.employee_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion *
                            </label>
                            <select
                                :value="selectedOptionValue"
                                @change="onTypeChange"
                                :disabled="!form.employee_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                                :class="{ 'border-red-500': form.errors.type }"
                            >
                                <option value="">{{ form.employee_id ? 'Seleccionar...' : 'Selecciona primero un empleado' }}</option>
                                <option v-for="type in compensationTypes" :key="type.compensation_type_id" :value="optionValue(type)">
                                    {{ type.label }}
                                </option>
                            </select>
                            <p v-if="form.employee_id && compensationTypes.length === 0" class="mt-1 text-xs text-amber-600">
                                Este empleado no tiene tipos de autorizacion habilitados.
                            </p>
                            <p v-if="form.type && typeDescriptions[form.type]" class="mt-1 text-sm text-gray-500">
                                {{ typeDescriptions[form.type] }}
                            </p>
                            <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.type }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Live suggestion from schedule + attendance -->
                <div v-if="canSuggest && suggestion && !prefill?.anomaly_id" class="rounded-lg p-4 border-l-4"
                    :class="suggestion.found ? 'bg-amber-50 border-amber-400' : 'bg-gray-50 border-gray-300'">
                    <div v-if="suggestion.found" class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z" />
                        </svg>
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-amber-800">Sugerencia basada en checadas reales</h4>
                            <p class="text-sm text-amber-700 mt-1">{{ suggestion.summary }}</p>
                            <p class="text-xs text-amber-600 mt-1">
                                Horario sugerido: <strong>{{ suggestion.start_time }} - {{ suggestion.end_time }}</strong> ({{ suggestion.hours }}h)
                            </p>
                            <button type="button" @click="applySuggestion"
                                class="mt-2 px-3 py-1.5 bg-amber-600 text-white text-xs rounded hover:bg-amber-700">
                                Aplicar sugerencia
                            </button>
                        </div>
                    </div>
                    <div v-else class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <p class="text-sm text-gray-600">{{ suggestion.message }}</p>
                    </div>
                </div>
                <div v-else-if="canSuggest && suggestionLoading" class="bg-gray-50 rounded-lg p-3 text-sm text-gray-500">
                    Calculando sugerencia desde checadas...
                </div>

                <!-- Date & Time - adapts to application_mode -->
                <div v-if="selectedApplicationMode" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">{{ dateCardTitle }}</h3>
                    <p v-if="selectedTypeLabel" class="text-xs text-gray-500 mb-4">Aplicara como <strong>{{ selectedTypeLabel }}</strong></p>

                    <!-- per_hour: datetime-local start + end + auto hours -->
                    <div v-if="selectedApplicationMode === 'per_hour'" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha/Hora Inicio *</label>
                            <input v-model="startDatetime" type="datetime-local" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.date || form.errors.start_time }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                            <p v-if="form.errors.start_time" class="mt-1 text-sm text-red-600">{{ form.errors.start_time }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha/Hora Fin *</label>
                            <input v-model="endDatetime" type="datetime-local" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.end_time }" />
                            <p v-if="form.errors.end_time" class="mt-1 text-sm text-red-600">{{ form.errors.end_time }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Horas Totales</label>
                            <input v-model="form.hours" type="number" step="0.5" min="0" max="48" placeholder="Auto" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.hours }" />
                            <p v-if="form.errors.hours" class="mt-1 text-sm text-red-600">{{ form.errors.hours }}</p>
                            <p class="mt-1 text-xs text-gray-500">Se calcula automaticamente</p>
                        </div>
                    </div>

                    <!-- per_day: date start + date end (no times) -->
                    <div v-else-if="selectedApplicationMode === 'per_day'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio *</label>
                            <input v-model="startDate" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.date }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin *</label>
                            <input v-model="endDate" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            <p v-if="form.hours" class="mt-1 text-sm text-gray-500">{{ form.hours }} dia(s)</p>
                        </div>
                    </div>

                    <!-- one_time: date + quantity -->
                    <div v-else-if="selectedApplicationMode === 'one_time'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha *</label>
                            <input v-model="startDate" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.date }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
                            <input v-model="form.hours" type="number" step="1" min="1" placeholder="1" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.hours }" />
                            <p v-if="form.errors.hours" class="mt-1 text-sm text-red-600">{{ form.errors.hours }}</p>
                            <p class="mt-1 text-xs text-gray-500">Numero de unidades (el monto se calcula del valor asignado al empleado)</p>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Justificacion</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Razon / Motivo *
                        </label>
                        <textarea
                            v-model="form.reason"
                            rows="4"
                            placeholder="Describa el motivo de esta autorizacion..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('authorizations.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Crear Autorizacion' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
