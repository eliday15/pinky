<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { todayLocal } from '@/utils/date';
import { diffMinutes, formatRoundedHours } from '@/utils/overtime';

const props = defineProps({
    employees: Array,
    selectedEmployee: [Number, String],
    types: Array,
    prefill: { type: Object, default: null },
    departments: { type: Array, default: () => [] },
});

/** Department filter narrows the SearchableSelect options so users with many
 *  employees can jump straight to the right team before searching. */
const departmentFilter = ref('');

const filteredEmployees = computed(() => {
    if (!departmentFilter.value) return props.employees;
    return props.employees.filter(e => e.department_id == departmentFilter.value);
});

const today = todayLocal();
const initialDate = props.prefill?.date || today;
const initialStartTime = props.prefill?.start_time || '08:00';
const initialEndTime = props.prefill?.end_time || '16:00';

// Per_day / one_time legacy state (kept for non-per_hour types).
const startDatetime = ref(`${initialDate}T${initialStartTime}`);
const endDatetime = ref(`${initialDate}T${initialEndTime}`);
const startDate = ref(initialDate);
const endDate = ref(initialDate);

// Per_hour range pickers for "Cargar desde checadas".
const rangeStart = ref(initialDate);
const rangeEnd = ref(initialDate);

const form = useForm({
    // Legacy single-row fields (per_day / one_time path).
    employee_id: props.prefill?.employee_id || props.selectedEmployee || '',
    type: props.prefill?.type || '',
    compensation_type_id: props.prefill?.compensation_type_id || null,
    date: initialDate,
    start_time: props.prefill?.start_time || '',
    end_time: props.prefill?.end_time || '',
    hours: props.prefill?.hours || '',
    reason: props.prefill?.reason || '',
    anomaly_id: props.prefill?.anomaly_id || null,
    // Per_hour entries — multi-day flat list submitted to storeBulk.
    // employee_ids is filled with the single selected employee so storeBulk's
    // legacy branch validates when entries[] is empty.
    employee_ids: [],
    entries: [],
});

const selectedApplicationMode = computed(() => {
    if (!form.compensation_type_id) return null;
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.application_mode || null;
});

const selectedTypeLabel = computed(() => {
    if (!form.compensation_type_id) return '';
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.label || '';
});

const dateCardTitle = computed(() => {
    switch (selectedApplicationMode.value) {
        case 'per_hour': return 'Fecha y Horario';
        case 'per_day': return 'Rango de Fechas';
        case 'one_time': return 'Fecha y Cantidad';
        default: return 'Fecha y Horario';
    }
});

const isPerHour = computed(() => selectedApplicationMode.value === 'per_hour');

/** Legacy hours auto-calc when datetimes change (per_hour single submit). */
watch([startDatetime, endDatetime], ([start, end]) => {
    if (start && end && selectedApplicationMode.value === 'per_hour') {
        const s = new Date(start);
        const e = new Date(end);
        if (e > s) form.hours = ((e - s) / (1000 * 60 * 60)).toFixed(2);
        form.date = start.split('T')[0];
        form.start_time = start.split('T')[1] || '';
        form.end_time = end.split('T')[1] || '';
    }
});

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

watch(startDate, (val) => {
    if (selectedApplicationMode.value === 'one_time') {
        form.date = val;
        form.start_time = '';
        form.end_time = '';
    }
});

/* ----- Suggestion state for per_hour multi-day mode ----- */
const suggestions = ref([]);
const suggestionsLoading = ref(false);
const suggestionsApplied = ref(false);
const eligibleDayCount = ref(0);

function resetEntries() {
    suggestions.value = [];
    suggestionsApplied.value = false;
    eligibleDayCount.value = 0;
    form.entries = [];
}

/** Type / employee / date-range changes invalidate any cached suggestions. */
watch(() => form.compensation_type_id, () => resetEntries());
watch(() => form.employee_id, () => resetEntries());
watch([rangeStart, rangeEnd], () => resetEntries());

const canFetchSuggestions = computed(() => {
    return isPerHour.value
        && form.employee_id
        && rangeStart.value
        && rangeEnd.value
        && (form.type === 'overtime' || form.type === 'night_shift');
});

const applySuggestions = () => {
    if (suggestions.value.length === 0) return;
    const selectedEmp = props.employees.find(e => e.id == form.employee_id);
    form.entries = suggestions.value.map(s => ({
        employee_id: s.employee_id,
        employee_name: s.employee_name || selectedEmp?.full_name || '',
        employee_number: s.employee_number || selectedEmp?.employee_number || '',
        date: s.date,
        start_time: s.start_time,
        end_time: s.end_time,
        hours: s.hours,
        summary: s.summary,
        kind: s.kind,
    }));
    suggestionsApplied.value = true;
};

const fetchSuggestions = async () => {
    if (!canFetchSuggestions.value) return;
    suggestionsLoading.value = true;
    suggestionsApplied.value = false;
    try {
        const { data } = await axios.get(route('authorizations.suggestBulk'), {
            params: {
                employee_ids: [form.employee_id],
                start_date: rangeStart.value,
                end_date: rangeEnd.value,
                type: form.type,
            },
        });
        suggestions.value = data.suggestions || [];
        eligibleDayCount.value = data.eligible_count || suggestions.value.length;
        if (suggestions.value.length > 0) applySuggestions();
    } catch (err) {
        suggestions.value = [];
        eligibleDayCount.value = 0;
        if (err?.response?.data?.message) alert(err.response.data.message);
    } finally {
        suggestionsLoading.value = false;
    }
};

const clearSuggestions = () => resetEntries();

const totalEntryHours = computed(() => {
    return form.entries.reduce((sum, e) => sum + (parseFloat(e.hours) || 0), 0).toFixed(2);
});

const setEntryField = (index, field, value) => {
    const next = [...form.entries];
    const row = { ...next[index], [field]: value };
    if (field === 'start_time' || field === 'end_time') {
        row.hours = formatRoundedHours(diffMinutes(row.start_time, row.end_time));
    }
    next[index] = row;
    form.entries = next;
};

const removeEntry = (index) => {
    const next = [...form.entries];
    next.splice(index, 1);
    form.entries = next;
};

const addManualEntry = () => {
    if (!form.employee_id) return;
    const emp = props.employees.find(e => e.id == form.employee_id);
    form.entries = [
        ...form.entries,
        {
            employee_id: Number(form.employee_id),
            employee_name: emp?.full_name || '',
            employee_number: emp?.employee_number || '',
            date: rangeStart.value || today,
            start_time: '',
            end_time: '',
            hours: '',
            summary: '',
            kind: 'manual',
        },
    ];
};

const getEntryDatetime = (entry, field) => {
    if (!entry[field]) return '';
    return `${entry.date}T${entry[field]}`;
};

const setEntryDatetime = (index, field, value) => {
    if (!value) {
        setEntryField(index, field, '');
        return;
    }
    const [datePart, timePart] = value.split('T');
    const next = [...form.entries];
    const row = { ...next[index] };
    if (datePart) row.date = datePart;
    if (timePart) row[field] = timePart;
    row.hours = formatRoundedHours(diffMinutes(row.start_time, row.end_time));
    next[index] = row;
    form.entries = next;
};

const formatDateShort = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}`;
};

/** Active compensation type IDs for the selected employee. */
const selectedEmployeeData = computed(() => {
    if (!form.employee_id) return null;
    return props.employees.find(e => e.id == form.employee_id);
});

const compensationTypes = computed(() => {
    const all = props.types.filter(t => t.group === 'compensation');
    const ids = selectedEmployeeData.value?.active_compensation_type_ids;
    if (!ids) return all;
    return all.filter(t => ids.includes(t.compensation_type_id));
});

const optionValue = (type) => `comp_${type.compensation_type_id}`;

const selectedOptionValue = computed(() => {
    if (form.compensation_type_id) return `comp_${form.compensation_type_id}`;
    return form.type;
});

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

/** Department filter change: drop the current employee if it no longer
 *  fits the filter, so the SearchableSelect doesn't display a value that
 *  isn't in its options. */
watch(departmentFilter, () => {
    if (!form.employee_id) return;
    if (!filteredEmployees.value.some(e => e.id == form.employee_id)) {
        form.employee_id = '';
        form.type = '';
        form.compensation_type_id = null;
    }
});

/** When the chosen employee drops the currently selected type, reset it. */
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

/** Per_hour submits via the bulk endpoint (one Authorization per entry).
 *  Non-per_hour keeps the legacy single-record store endpoint. */
const submit = () => {
    if (isPerHour.value && form.entries.length > 0) {
        form.employee_ids = [Number(form.employee_id)];
        form.post(route('authorizations.storeBulk'));
        return;
    }
    form.post(route('authorizations.store'));
};

const canSubmit = computed(() => {
    if (form.processing) return false;
    if (!form.employee_id || !form.type) return false;
    if (isPerHour.value) return form.entries.length > 0;
    return true;
});

const submitCount = computed(() => (isPerHour.value ? form.entries.length : 1));
</script>

<template>
    <Head title="Nueva Autorizacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nueva Autorizacion
            </h2>
        </template>

        <div class="max-w-4xl">
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

                <!-- Step 1: Employee + Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">1</span>
                        Empleado y Tipo
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                            <select
                                v-model="departmentFilter"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            >
                                <option value="">Todos</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                            <p v-if="departmentFilter" class="mt-1 text-xs text-gray-500">
                                {{ filteredEmployees.length }} empleado(s) en este departamento
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Empleado *</label>
                            <SearchableSelect
                                v-model="form.employee_id"
                                :options="filteredEmployees"
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Autorizacion *</label>
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

                <!-- Step 2 (per_hour): Date range + entries table for this employee -->
                <div v-if="isPerHour && form.employee_id" class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">2</span>
                                Horas a Autorizar
                            </h3>
                            <p class="text-xs text-gray-500">
                                Cada fila es una autorización (un día). Elige un rango y carga las horas detectadas en checadas, o agrega manualmente.
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                {{ form.entries.length }} fila(s) · Total: <strong>{{ totalEntryHours }}h</strong>
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-2 flex-shrink-0">
                            <div class="flex items-center gap-2">
                                <label class="text-xs text-gray-600">Desde:</label>
                                <input type="date" v-model="rangeStart"
                                    class="text-xs rounded border-gray-300 focus:border-pink-500 focus:ring-pink-500 py-1" />
                                <label class="text-xs text-gray-600">Hasta:</label>
                                <input type="date" v-model="rangeEnd" :min="rangeStart"
                                    class="text-xs rounded border-gray-300 focus:border-pink-500 focus:ring-pink-500 py-1" />
                            </div>
                            <div class="flex gap-2">
                                <button type="button" @click="fetchSuggestions"
                                    :disabled="suggestionsLoading || !canFetchSuggestions"
                                    class="px-3 py-1.5 bg-amber-600 text-white text-xs rounded hover:bg-amber-700 disabled:opacity-50">
                                    {{ suggestionsLoading ? 'Calculando...' : 'Cargar desde checadas' }}
                                </button>
                                <button type="button" @click="addManualEntry"
                                    class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">
                                    + Agregar fila
                                </button>
                                <button v-if="suggestionsApplied || form.entries.length > 0" type="button" @click="clearSuggestions"
                                    class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">
                                    Limpiar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="suggestionsApplied" class="mb-3 bg-amber-50 border border-amber-200 rounded-lg p-2 text-xs text-amber-800 space-y-1">
                        <p>Se cargaron <strong>{{ form.entries.length }}</strong> fila(s) con tiempo extra detectado.</p>
                        <p>Redondeo: &lt;30 min no cuenta · 30–49 min = 0.5h · 50 min en adelante = 1h (y así, sumando 0.5h en :30 y 1h completo en :50).</p>
                    </div>

                    <div v-if="form.entries.length === 0" class="border rounded-lg p-6 text-center text-sm text-gray-500">
                        No hay filas todavía. Define un rango y carga desde checadas, o agrega una manualmente.
                    </div>
                    <div v-else class="border rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-2 grid grid-cols-12 gap-2 text-xs font-medium text-gray-700">
                            <div class="col-span-2">Día</div>
                            <div class="col-span-4">Fecha/Hora Inicio</div>
                            <div class="col-span-4">Fecha/Hora Fin</div>
                            <div class="col-span-1">Horas</div>
                            <div class="col-span-1"></div>
                        </div>
                        <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                            <div v-for="(entry, idx) in form.entries" :key="`${entry.date}_${entry.kind}_${idx}`"
                                class="px-4 py-2 grid grid-cols-12 gap-2 items-center text-sm bg-white">
                                <div class="col-span-2 min-w-0">
                                    <div class="text-xs font-semibold text-pink-700">{{ formatDateShort(entry.date) }}</div>
                                    <div v-if="entry.summary" class="text-[10px] text-amber-700 truncate" :title="entry.summary">
                                        {{ entry.summary }}
                                    </div>
                                </div>
                                <input type="datetime-local"
                                    :value="getEntryDatetime(entry, 'start_time')"
                                    @input="setEntryDatetime(idx, 'start_time', $event.target.value)"
                                    class="col-span-4 rounded border-gray-300 text-xs focus:border-pink-500 focus:ring-pink-500" />
                                <input type="datetime-local"
                                    :value="getEntryDatetime(entry, 'end_time')"
                                    @input="setEntryDatetime(idx, 'end_time', $event.target.value)"
                                    class="col-span-4 rounded border-gray-300 text-xs focus:border-pink-500 focus:ring-pink-500" />
                                <input type="text" readonly
                                    :value="entry.hours"
                                    title="Calculado automáticamente desde inicio/fin con la regla escalonada"
                                    class="col-span-1 rounded border-gray-200 bg-gray-50 text-xs text-gray-700 text-right cursor-not-allowed" />
                                <button type="button" @click="removeEntry(idx)"
                                    class="col-span-1 text-gray-400 hover:text-red-600 text-xs">
                                    Quitar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2 (legacy): Date & Time for per_day / one_time -->
                <div v-if="selectedApplicationMode && !isPerHour" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">2</span>
                        {{ dateCardTitle }}
                    </h3>
                    <p v-if="selectedTypeLabel" class="text-xs text-gray-500 mb-4">Aplicara como <strong>{{ selectedTypeLabel }}</strong></p>

                    <div v-if="selectedApplicationMode === 'per_day'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
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
                            <p class="mt-1 text-xs text-gray-500">Numero de unidades</p>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Justificacion</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Razon / Motivo *</label>
                        <textarea v-model="form.reason" rows="3"
                            placeholder="Describa el motivo de esta autorizacion..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">{{ form.errors.reason }}</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link :href="route('authorizations.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancelar
                    </Link>
                    <button type="submit" :disabled="!canSubmit"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50">
                        {{ form.processing ? 'Guardando...' : (submitCount > 1 ? `Crear ${submitCount} Autorizaciones` : 'Crear Autorización') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
