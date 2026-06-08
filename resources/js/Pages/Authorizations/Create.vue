<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { todayLocal } from '@/utils/date';
import { formatRoundedHours, minutesBetweenDates } from '@/utils/overtime';

const props = defineProps({
    employees: Array,
    selectedEmployee: [Number, String],
    types: Array,
    prefill: { type: Object, default: null },
    departments: { type: Array, default: () => [] },
    holidays: { type: Array, default: () => [] },
    // Cuando el usuario puede aprobar, el alta queda aprobada en el mismo paso;
    // el botón lo refleja ("Crear y aprobar").
    canApprove: { type: Boolean, default: false },
});

const holidaySet = computed(() => new Set(props.holidays || []));

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const toMin = (hhmm) => {
    if (!hhmm) return null;
    const [h, m] = hhmm.split(':').map(Number);
    return isNaN(h) || isNaN(m) ? null : h * 60 + m;
};

/** Mirrors the backend overlapsWorkSchedule check so per-hour authorizations
 *  inside the employee's regular jornada are blocked before submit. Holidays
 *  bypass the rule. */
const hasScheduleConflict = (entry) => {
    if (!entry?.date || !entry?.start_time || !entry?.end_time) return false;
    if (holidaySet.value.has(entry.date)) return false;
    const emp = props.employees.find(e => e.id == entry.employee_id);
    const dayName = DAY_NAMES[new Date(entry.date + 'T12:00:00').getDay()];
    const sched = emp?.schedule_by_day?.[dayName];
    if (!sched) return false;
    const eMin = toMin(sched.entry);
    const xMin = toMin(sched.exit);
    const s = toMin(entry.start_time);
    const f = toMin(entry.end_time);
    if (eMin == null || xMin == null || s == null || f == null) return false;
    return s < xMin && f > eMin;
};

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

/** The attendance_pull_rule of the selected compensation type. */
const selectedPullRule = computed(() => {
    if (!form.compensation_type_id) return null;
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.attendance_pull_rule || null;
});

/** Whether the selected type pulls per-day entries from check-ins (meal/weekend),
 *  as one entry per qualifying day, never auto-approved. */
const isAttendancePull = computed(() => ['meal', 'weekend', 'comida'].includes(selectedPullRule.value));

/** Copy for the attendance-pull card, by rule. */
const pullCopy = computed(() => {
    if (selectedPullRule.value === 'weekend') {
        return {
            title: 'Fines de Semana a Autorizar',
            hint: 'Cada fila es un día de fin de semana trabajado (sáb/dom fuera de su horario). Elige un rango y carga desde checadas.',
            unit: 'fin(es) de semana',
        };
    }
    if (selectedPullRule.value === 'comida') {
        return {
            title: 'Comidas a Autorizar',
            hint: 'Cada fila es una comida (un día). Elige un rango y carga desde checadas: se genera una comida por cada día de fin de semana trabajado (sáb/dom fuera de su horario).',
            unit: 'comida(s)',
        };
    }
    return {
        title: 'Cenas a Autorizar',
        hint: 'Cada fila es una cena (un día). Elige un rango y carga desde checadas: se genera una cena por cada día con jornada larga, velada (cruzó medianoche) o trabajo en fin de semana.',
        unit: 'cena(s)',
    };
});

const dateCardTitle = computed(() => {
    switch (selectedApplicationMode.value) {
        case 'per_hour': return 'Fecha y Horario';
        case 'per_day': return 'Rango de Fechas';
        case 'one_time': return 'Fecha y Cantidad';
        default: return 'Fecha y Horario';
    }
});

// A pull-rule type (cena/fin de semana/comida) always uses the attendance-pull
// card, even if it were configured per_hour — never the per-hour hours table.
const isPerHour = computed(() => selectedApplicationMode.value === 'per_hour' && !isAttendancePull.value);

/** per_day / one_time keep their range/quantity form, but also allow extra
 *  loose rows (each row = one date + a quantity). */
const isQuantityMode = computed(() =>
    selectedApplicationMode.value === 'per_day' || selectedApplicationMode.value === 'one_time');

/** one_time authorizes a unit quantity, not a worked date — its extra rows
 *  only need a quantity (a date is still sent to satisfy the backend). */
const isOneTime = computed(() => selectedApplicationMode.value === 'one_time');

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
    if (!form.employee_id || !rangeStart.value || !rangeEnd.value) return false;
    if (isAttendancePull.value) return true;
    return isPerHour.value
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
        // A velada whose end time is earlier than its start crossed midnight,
        // so the end belongs to the next day.
        end_date: inferEndDate(s.date, s.start_time, s.end_time),
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
                compensation_type_id: form.compensation_type_id,
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

/** Add one day to an ISO date string (YYYY-MM-DD). */
const addOneDay = (iso) => {
    if (!iso) return iso;
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + 1);
    return d.toISOString().split('T')[0];
};

/** A range whose end time is at or before its start time crossed midnight, so
 *  the end falls on the following day. */
const inferEndDate = (date, startTime, endTime) => {
    if (!date || !startTime || !endTime) return date;
    return toMin(endTime) < toMin(startTime) ? addOneDay(date) : date;
};

const recomputeHours = (row) => {
    const endDate = row.end_date || row.date;
    return formatRoundedHours(minutesBetweenDates(row.date, row.start_time, endDate, row.end_time));
};

const setEntryField = (index, field, value) => {
    const next = [...form.entries];
    const row = { ...next[index], [field]: value };
    if (field === 'start_time' || field === 'end_time') {
        row.hours = recomputeHours(row);
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
    const qty = isQuantityMode.value;
    const defaultDate = qty ? (startDate.value || today) : (rangeStart.value || today);
    form.entries = [
        ...form.entries,
        {
            employee_id: Number(form.employee_id),
            employee_name: emp?.full_name || '',
            employee_number: emp?.employee_number || '',
            date: defaultDate,
            end_date: defaultDate,
            start_time: '',
            end_time: '',
            hours: qty ? '1' : '',
            summary: '',
            kind: 'manual',
        },
    ];
};

/** Start uses the row's main date; end uses its own end_date so a velada can
 *  span two days. */
const getEntryDatetime = (entry, field) => {
    if (!entry[field]) return '';
    const datePart = field === 'end_time' ? (entry.end_date || entry.date) : entry.date;
    return `${datePart}T${entry[field]}`;
};

/** Editing the start moves the row's main date; editing the end moves only
 *  end_date, so setting the end never clobbers the start. */
const setEntryDatetime = (index, field, value) => {
    const next = [...form.entries];
    const row = { ...next[index] };
    if (!value) {
        row[field] = '';
    } else {
        const [datePart, timePart] = value.split('T');
        if (field === 'end_time') {
            if (datePart) row.end_date = datePart;
            if (timePart) row.end_time = timePart;
        } else {
            if (datePart) row.date = datePart;
            if (timePart) row.start_time = timePart;
            if (!row.end_date) row.end_date = datePart;
        }
    }
    row.hours = recomputeHours(row);
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
    if ((isPerHour.value || isAttendancePull.value) && form.entries.length > 0) {
        form.employee_ids = [Number(form.employee_id)];
        form.post(route('authorizations.storeBulk'));
        return;
    }
    // per_day / one_time with extra manual rows: fold the range/quantity form
    // and the extra rows into a single entries[] payload (the bulk endpoint),
    // so both are created in one request.
    if (isQuantityMode.value && form.entries.length > 0) {
        const emp = props.employees.find(e => e.id == form.employee_id);
        const rangeRow = {
            employee_id: Number(form.employee_id),
            employee_name: emp?.full_name || '',
            employee_number: emp?.employee_number || '',
            date: form.date,
            start_time: '',
            end_time: '',
            hours: form.hours || '1',
            summary: '',
            kind: 'range',
        };
        const merged = [rangeRow, ...form.entries];
        form.employee_ids = [Number(form.employee_id)];
        form.transform((data) => ({ ...data, entries: merged }))
            .post(route('authorizations.storeBulk'), {
                onFinish: () => form.transform((data) => data),
            });
        return;
    }
    form.post(route('authorizations.store'));
};

const isHoursType = computed(() => form.type === 'overtime' || form.type === 'night_shift');

const conflictedEntries = computed(() => {
    if (!isHoursType.value) return [];
    return form.entries.filter(hasScheduleConflict);
});

const zeroHourEntries = computed(() => {
    if (!isHoursType.value) return [];
    return form.entries.filter(e => (parseFloat(e.hours) || 0) <= 0);
});

const canSubmit = computed(() => {
    if (form.processing) return false;
    if (!form.employee_id || !form.type) return false;
    if (isPerHour.value) {
        if (form.entries.length === 0) return false;
        if (conflictedEntries.value.length > 0) return false;
        if (zeroHourEntries.value.length > 0) return false;
        return true;
    }
    // Attendance-pull (Cena / Fin de semana): one per-day entry per qualifying
    // day, no hour/conflict rules.
    if (isAttendancePull.value) {
        return form.entries.length > 0;
    }
    return true;
});

const submitCount = computed(() => {
    if (isPerHour.value || isAttendancePull.value) return form.entries.length;
    // Quantity modes: one auth from the range/quantity form, plus the extra rows.
    if (isQuantityMode.value) return 1 + form.entries.length;
    return 1;
});
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

                    <div v-if="isHoursType && conflictedEntries.length > 0"
                        class="mb-3 bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-800">
                        <strong>{{ conflictedEntries.length }}</strong> fila(s) caen dentro del horario laboral del empleado y no pueden autorizarse.
                        Ajusta los tiempos para que queden <em>fuera</em> de la jornada, o quita esas filas. Día festivo se exenta de esta regla.
                    </div>

                    <div v-if="isHoursType && zeroHourEntries.length > 0"
                        class="mb-3 bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
                        <strong>{{ zeroHourEntries.length }}</strong> fila(s) tienen <strong>0 horas</strong> (rango menor a 30 min se redondea a 0). Amplía el rango o quita esas filas.
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
                            <template v-for="(entry, idx) in form.entries" :key="`${entry.date}_${entry.kind}_${idx}`">
                                <div class="px-4 py-2 grid grid-cols-12 gap-2 items-center text-sm"
                                    :class="isHoursType && hasScheduleConflict(entry) ? 'bg-red-50' : (isHoursType && (parseFloat(entry.hours) || 0) <= 0 ? 'bg-amber-50' : 'bg-white')">
                                    <div class="col-span-2 min-w-0">
                                        <div class="text-xs font-semibold"
                                            :class="isHoursType && hasScheduleConflict(entry) ? 'text-red-700' : (isHoursType && (parseFloat(entry.hours) || 0) <= 0 ? 'text-amber-700' : 'text-pink-700')">
                                            {{ formatDateShort(entry.date) }}
                                        </div>
                                        <div v-if="entry.summary" class="text-[10px] text-amber-700 truncate" :title="entry.summary">
                                            {{ entry.summary }}
                                        </div>
                                    </div>
                                    <input type="datetime-local"
                                        :value="getEntryDatetime(entry, 'start_time')"
                                        @input="setEntryDatetime(idx, 'start_time', $event.target.value)"
                                        class="col-span-4 rounded text-xs focus:border-pink-500 focus:ring-pink-500"
                                        :class="isHoursType && hasScheduleConflict(entry) ? 'border-red-400' : 'border-gray-300'" />
                                    <input type="datetime-local"
                                        :value="getEntryDatetime(entry, 'end_time')"
                                        @input="setEntryDatetime(idx, 'end_time', $event.target.value)"
                                        class="col-span-4 rounded text-xs focus:border-pink-500 focus:ring-pink-500"
                                        :class="isHoursType && hasScheduleConflict(entry) ? 'border-red-400' : 'border-gray-300'" />
                                    <input type="text" readonly
                                        :value="entry.hours"
                                        title="Calculado automáticamente desde inicio/fin con la regla escalonada"
                                        class="col-span-1 rounded border-gray-200 bg-gray-50 text-xs text-gray-700 text-right cursor-not-allowed" />
                                    <button type="button" @click="removeEntry(idx)"
                                        class="col-span-1 text-gray-400 hover:text-red-600 text-xs">
                                        Quitar
                                    </button>
                                </div>
                                <div v-if="isHoursType && hasScheduleConflict(entry)"
                                    class="px-4 pb-2 -mt-1 text-[11px] text-red-700 bg-red-50">
                                    ⚠ Las horas caen dentro de su jornada laboral. Solo se autoriza fuera de horario (o en día festivo).
                                </div>
                                <div v-else-if="isHoursType && (parseFloat(entry.hours) || 0) <= 0"
                                    class="px-4 pb-2 -mt-1 text-[11px] text-amber-700 bg-amber-50">
                                    ⚠ Rango menor a 30 min se redondea a 0 horas. Amplía el rango para poder autorizarlo.
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Step 2 (attendance pull): date range + qualifying days from check-ins -->
                <div v-if="isAttendancePull && form.employee_id" class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">2</span>
                                {{ pullCopy.title }}
                            </h3>
                            <p class="text-xs text-gray-500">
                                {{ pullCopy.hint }}
                            </p>
                            <p class="mt-2 text-xs text-gray-500">
                                {{ form.entries.length }} {{ pullCopy.unit }} detectada(s)
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

                    <div v-if="suggestionsApplied" class="mb-3 bg-amber-50 border border-amber-200 rounded-lg p-2 text-xs text-amber-800">
                        <p>Se cargaron <strong>{{ form.entries.length }}</strong> {{ pullCopy.unit }}. Revísalas y crea: quedarán <strong>pendientes</strong> de aprobación.</p>
                    </div>

                    <div v-if="form.entries.length === 0" class="border rounded-lg p-6 text-center text-sm text-gray-500">
                        No hay {{ pullCopy.unit }} todavía. Define un rango y carga desde checadas.
                    </div>
                    <div v-else class="border rounded-lg overflow-hidden divide-y divide-gray-100">
                        <div v-for="(entry, idx) in form.entries" :key="`${entry.date}_${idx}`"
                            class="px-4 py-2 flex items-center justify-between gap-3 text-sm bg-white">
                            <div class="min-w-0">
                                <input v-if="entry.kind === 'manual'" type="date"
                                    :value="entry.date"
                                    @input="setEntryField(idx, 'date', $event.target.value)"
                                    class="text-xs rounded border-gray-300 focus:border-pink-500 focus:ring-pink-500 py-1" />
                                <div v-else class="text-xs font-semibold text-pink-700">{{ formatDateShort(entry.date) }}</div>
                                <div v-if="entry.summary" class="text-[11px] text-gray-600 truncate" :title="entry.summary">
                                    {{ entry.summary }}
                                </div>
                            </div>
                            <button type="button" @click="removeEntry(idx)"
                                class="text-[11px] text-gray-400 hover:text-red-600 flex-shrink-0">
                                Quitar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2 (legacy): Date & Time for per_day / one_time -->
                <div v-if="selectedApplicationMode && !isPerHour && !isAttendancePull" class="bg-white rounded-lg shadow p-6">
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

                    <!-- Extra manual rows (additive to the range/quantity above) -->
                    <div class="mt-6 pt-4 border-t border-gray-100">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs text-gray-500">
                                Filas adicionales.
                                <span v-if="isOneTime">Solo cantidad.</span>
                                <span v-else>Cada fila es una fecha extra.</span>
                            </p>
                            <button type="button" @click="addManualEntry"
                                class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">
                                + Agregar fila
                            </button>
                        </div>

                        <div v-if="form.entries.length > 0" class="border rounded-lg overflow-hidden divide-y divide-gray-100">
                            <div v-for="(entry, idx) in form.entries" :key="`q_${idx}`"
                                class="px-4 py-2 flex items-center gap-3 text-sm bg-white">
                                <div v-if="!isOneTime" class="flex-shrink-0">
                                    <input type="date" :value="entry.date"
                                        @input="setEntryField(idx, 'date', $event.target.value)"
                                        class="text-xs rounded border-gray-300 focus:border-pink-500 focus:ring-pink-500 py-1" />
                                </div>
                                <div class="flex items-center gap-1">
                                    <label class="text-xs text-gray-500">Cant.</label>
                                    <input type="number" step="1" min="1" :value="entry.hours"
                                        @input="setEntryField(idx, 'hours', $event.target.value)"
                                        class="w-20 text-xs rounded border-gray-300 focus:border-pink-500 focus:ring-pink-500 py-1" />
                                </div>
                                <button type="button" @click="removeEntry(idx)"
                                    class="ml-auto text-[11px] text-gray-400 hover:text-red-600 flex-shrink-0">
                                    Quitar
                                </button>
                            </div>
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
                        {{ form.processing ? 'Guardando...' : (canApprove
                            ? (submitCount > 1 ? `Crear y aprobar ${submitCount}` : 'Crear y aprobar')
                            : (submitCount > 1 ? `Crear ${submitCount} Autorizaciones` : 'Crear Autorización')) }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
