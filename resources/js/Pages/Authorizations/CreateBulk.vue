<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { todayLocal } from '@/utils/date';
import { diffMinutes, formatRoundedHours } from '@/utils/overtime';

const props = defineProps({
    employees: Array,
    types: Array,
    departments: Array,
});

const today = todayLocal();

// Legacy single-date inputs for per_day / one_time. Per_hour uses entries[].
const startDatetime = ref(`${today}T08:00`);
const endDatetime = ref(`${today}T16:00`);
const startDate = ref(today);
const endDate = ref(today);

// Date range for "Cargar desde checadas" — only relevant for per_hour types.
const rangeStart = ref(today);
const rangeEnd = ref(today);

const form = useForm({
    employee_ids: [],
    type: '',
    compensation_type_id: null,
    // Legacy single-date submit (per_day / one_time).
    date: today,
    start_time: '',
    end_time: '',
    hours: '',
    reason: '',
    // Per-hour submit shape: flat list of {employee_id, date, start_time, end_time, hours}.
    entries: [],
});

/** The application_mode of the currently selected compensation type. */
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

/** Auto-calculate hours when datetimes change (legacy per_hour single submit). */
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

const searchQuery = ref('');
const selectAll = ref(false);
const departmentFilter = ref('');

/** All authorization types from the compensation catalog. */
const compensationTypes = computed(() => {
    return props.types.filter(t => t.group === 'compensation');
});

/** Employees who can receive the currently selected authorization type. */
const employeesForSelectedType = computed(() => {
    if (!form.compensation_type_id) return props.employees;
    return props.employees.filter(emp =>
        emp.active_compensation_type_ids?.includes(form.compensation_type_id)
    );
});

const filteredEmployees = computed(() => {
    let employees = employeesForSelectedType.value;

    if (departmentFilter.value) {
        employees = employees.filter(emp => emp.department_id == departmentFilter.value);
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        employees = employees.filter(emp =>
            emp.full_name.toLowerCase().includes(query) ||
            emp.employee_number.toLowerCase().includes(query)
        );
    }

    const selected = new Set(form.employee_ids);
    return [...employees].sort((a, b) => {
        const aSel = selected.has(a.id) ? 0 : 1;
        const bSel = selected.has(b.id) ? 0 : 1;
        if (aSel !== bSel) return aSel - bSel;
        return (a.full_name || '').localeCompare(b.full_name || '');
    });
});

const toggleSelectAll = () => {
    if (selectAll.value) {
        form.employee_ids = filteredEmployees.value.map(e => e.id);
    } else {
        form.employee_ids = [];
    }
};

const toggleEmployee = (empId) => {
    const index = form.employee_ids.indexOf(empId);
    if (index > -1) {
        form.employee_ids.splice(index, 1);
    } else {
        form.employee_ids.push(empId);
    }
    selectAll.value = form.employee_ids.length === filteredEmployees.value.length && filteredEmployees.value.length > 0;
};

const isSelected = (empId) => form.employee_ids.includes(empId);

const selectDepartment = () => {
    if (!departmentFilter.value) return;
    form.employee_ids = filteredEmployees.value.map(e => e.id);
    selectAll.value = true;
};

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

/** Pre-set night shift datetime defaults the first time the type changes to it. */
watch(() => form.type, (newType) => {
    if (newType === 'night_shift' && !form.start_time && !form.end_time) {
        const dateStr = startDatetime.value.split('T')[0] || today;
        startDatetime.value = `${dateStr}T22:00`;
        const nextDay = new Date(dateStr);
        nextDay.setDate(nextDay.getDate() + 1);
        const nextDayStr = nextDay.toISOString().split('T')[0];
        endDatetime.value = `${nextDayStr}T06:00`;
    }
});

/* -----------------------------------------------------------
 * State reset rules
 *   Changing what's being authorized invalidates everything
 *   downstream. We treat each lever separately so the user can
 *   tune one filter without losing unrelated picks.
 * ----------------------------------------------------------- */

/** Type change clears employee picks + downstream state. */
watch(() => form.compensation_type_id, (newCompId, oldCompId) => {
    if (newCompId === oldCompId) return;
    // Prune selection to the new type's eligible set (or wipe if no type).
    if (newCompId) {
        const allowedIds = new Set(employeesForSelectedType.value.map(e => e.id));
        form.employee_ids = form.employee_ids.filter(id => allowedIds.has(id));
    } else {
        form.employee_ids = [];
    }
    selectAll.value = false;
    resetBulkState();
});

/** Department filter change clears the prior bulk fetch — different scope. */
watch(departmentFilter, () => {
    selectAll.value = false;
    resetBulkState();
});

/** Date-range change for "Cargar desde checadas" invalidates cached data. */
watch([rangeStart, rangeEnd], () => {
    resetBulkState();
});

/** Legacy single-date change also clears cached data (per_day / one_time path). */
watch(() => form.date, () => {
    resetBulkState();
});

/* ----- Bulk live suggestions from schedule + attendance ----- */
const suggestions = ref([]);
const suggestionsLoading = ref(false);
const suggestionsApplied = ref(false);
const skippedCount = ref(0);
const eligibleEmployeeCount = ref(0);

const canSuggestBulk = computed(() => {
    return form.employee_ids.length > 0
        && rangeStart.value
        && rangeEnd.value
        && (form.type === 'overtime' || form.type === 'night_shift');
});

const applyBulkSuggestions = () => {
    if (suggestions.value.length === 0) return;
    const uniqueEmployeeIds = [...new Set(suggestions.value.map(s => s.employee_id))];
    form.employee_ids = uniqueEmployeeIds;
    form.entries = suggestions.value.map(s => ({
        employee_id: s.employee_id,
        employee_name: s.employee_name,
        employee_number: s.employee_number,
        date: s.date,
        start_time: s.start_time,
        end_time: s.end_time,
        hours: s.hours,
        summary: s.summary,
        kind: s.kind,
    }));
    suggestionsApplied.value = true;
};

const fetchBulkSuggestions = async () => {
    if (!canSuggestBulk.value) return;
    suggestionsLoading.value = true;
    suggestionsApplied.value = false;
    try {
        const { data } = await axios.get(route('authorizations.suggestBulk'), {
            params: {
                employee_ids: form.employee_ids,
                start_date: rangeStart.value,
                end_date: rangeEnd.value,
                type: form.type,
            },
        });
        suggestions.value = data.suggestions || [];
        skippedCount.value = data.skipped_count || 0;
        eligibleEmployeeCount.value = data.eligible_employee_count || 0;
        if (suggestions.value.length > 0) {
            applyBulkSuggestions();
        }
    } catch (err) {
        suggestions.value = [];
        skippedCount.value = 0;
        eligibleEmployeeCount.value = 0;
        if (err?.response?.data?.message) {
            alert(err.response.data.message);
        }
    } finally {
        suggestionsLoading.value = false;
    }
};

function resetBulkState() {
    suggestions.value = [];
    suggestionsApplied.value = false;
    skippedCount.value = 0;
    eligibleEmployeeCount.value = 0;
    form.entries = [];
}

const clearBulkSuggestions = () => {
    resetBulkState();
};

/* ----- Per-row table for per_hour types ----- */
const isPerHour = computed(() => selectedApplicationMode.value === 'per_hour');

/** Group rows by employee so the table reads as one block per person,
 *  with their days stacked beneath the name. */
const entriesGroupedByEmployee = computed(() => {
    const groups = new Map();
    for (let i = 0; i < form.entries.length; i++) {
        const e = form.entries[i];
        if (!groups.has(e.employee_id)) {
            groups.set(e.employee_id, {
                employee_id: e.employee_id,
                employee_name: e.employee_name || `Empleado #${e.employee_id}`,
                employee_number: e.employee_number || '',
                entries: [],
            });
        }
        groups.get(e.employee_id).entries.push({ ...e, _index: i });
    }
    return [...groups.values()]
        .map(g => ({
            ...g,
            entries: g.entries.sort((a, b) => (a.date || '').localeCompare(b.date || '')),
            total_hours: g.entries.reduce((s, e) => s + (parseFloat(e.hours) || 0), 0).toFixed(2),
        }))
        .sort((a, b) => a.employee_name.localeCompare(b.employee_name));
});

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
    if (form.employee_ids.length === 0) return;
    const empId = form.employee_ids[0];
    const emp = props.employees.find(e => e.id === empId);
    form.entries = [
        ...form.entries,
        {
            employee_id: empId,
            employee_name: emp?.full_name || `Empleado #${empId}`,
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

/** Compose a datetime-local string from row date + row time. */
const getEntryDatetime = (entry, field) => {
    if (!entry[field]) return '';
    return `${entry.date}T${entry[field]}`;
};

/** Update both date and time portions of a row from a datetime-local input. */
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

const getDepartmentName = (deptId) => {
    const dept = props.departments?.find(d => d.id == deptId);
    return dept ? dept.name : '';
};

const formatDateShort = (iso) => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}`;
};

const submit = () => {
    form.post(route('authorizations.storeBulk'));
};

const typeDescriptions = {
    overtime: 'Horas adicionales trabajadas fuera del horario normal',
    night_shift: 'Turno nocturno o velada completa',
    holiday_worked: 'Trabajo realizado en dia festivo oficial',
    special: 'Autorizacion especial que no encaja en otras categorias',
};

const submitButtonCount = computed(() => {
    if (isPerHour.value) return form.entries.length;
    return form.employee_ids.length;
});

const canSubmit = computed(() => {
    if (form.processing) return false;
    if (isPerHour.value) return form.entries.length > 0;
    return form.employee_ids.length > 0;
});
</script>

<template>
    <Head title="Autorizacion Masiva" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Autorizacion Masiva
            </h2>
        </template>

        <div class="max-w-5xl">
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Step 1: Authorization Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">1</span>
                        Tipo de Autorizacion
                    </h3>
                    <p class="text-xs text-gray-500 mb-4">
                        Elige el tipo de autorizacion. Despues podras seleccionar a los empleados que apliquen.
                    </p>
                    <select
                        :value="selectedOptionValue"
                        @change="onTypeChange"
                        class="w-full md:w-1/2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.type }"
                    >
                        <option value="">Seleccionar tipo...</option>
                        <option v-for="type in compensationTypes" :key="type.compensation_type_id" :value="optionValue(type)">
                            {{ type.label }}
                        </option>
                    </select>
                    <p v-if="form.type && typeDescriptions[form.type]" class="mt-2 text-sm text-gray-500">
                        {{ typeDescriptions[form.type] }}
                    </p>
                    <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                        {{ form.errors.type }}
                    </p>
                </div>

                <!-- Step 2: Employee Selection -->
                <div v-if="form.compensation_type_id" class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-3">
                        <h3 class="text-lg font-semibold text-gray-800">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">2</span>
                            Seleccionar Empleados
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                ({{ form.employee_ids.length }} seleccionados)
                            </span>
                        </h3>
                        <div class="flex items-center gap-3">
                            <select
                                v-model="departmentFilter"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                            >
                                <option value="">Todos los departamentos</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                            <button
                                v-if="departmentFilter"
                                type="button"
                                @click="selectDepartment"
                                class="px-3 py-2 text-xs bg-pink-100 text-pink-700 rounded-lg hover:bg-pink-200 whitespace-nowrap"
                            >
                                Seleccionar depto
                            </button>
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Buscar empleado..."
                                class="w-48 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                            />
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 mb-3">
                        Solo aparecen empleados con <strong>{{ selectedTypeLabel }}</strong> habilitado.
                    </p>

                    <div class="border rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-3 border-b flex items-center">
                            <input
                                type="checkbox"
                                v-model="selectAll"
                                @change="toggleSelectAll"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <span class="ml-3 text-sm font-medium text-gray-700">Seleccionar todos</span>
                            <span v-if="departmentFilter" class="ml-2 text-xs text-gray-500">
                                ({{ filteredEmployees.length }} en este depto)
                            </span>
                        </div>

                        <div class="max-h-64 overflow-y-auto">
                            <div
                                v-for="emp in filteredEmployees"
                                :key="emp.id"
                                class="px-4 py-3 border-b hover:bg-gray-50 flex items-center cursor-pointer"
                                @click="toggleEmployee(emp.id)"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isSelected(emp.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                    @click.stop
                                    @change="toggleEmployee(emp.id)"
                                />
                                <span class="ml-3 text-sm text-gray-900">{{ emp.full_name }}</span>
                                <span class="ml-2 text-xs text-gray-500">({{ emp.employee_number }})</span>
                                <span v-if="!departmentFilter && emp.department_id" class="ml-auto text-xs text-gray-400">
                                    {{ getDepartmentName(emp.department_id) }}
                                </span>
                            </div>
                            <div v-if="filteredEmployees.length === 0" class="px-4 py-8 text-center text-gray-500">
                                No hay empleados habilitados para este tipo.
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.employee_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.employee_ids }}
                    </p>
                </div>

                <!-- Step 3 (per_hour): Date range + entries table -->
                <div v-if="isPerHour && form.employee_ids.length > 0" class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">3</span>
                                Horas por Empleado
                            </h3>
                            <p class="text-xs text-gray-500">
                                Cada fila es una autorización (empleado + día). Elige un rango y carga las horas detectadas en checadas, o agrégalas manualmente.
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
                                <button type="button" @click="fetchBulkSuggestions"
                                    :disabled="suggestionsLoading || !rangeStart || !rangeEnd"
                                    class="px-3 py-1.5 bg-amber-600 text-white text-xs rounded hover:bg-amber-700 disabled:opacity-50">
                                    {{ suggestionsLoading ? 'Calculando...' : 'Cargar desde checadas' }}
                                </button>
                                <button type="button" @click="addManualEntry"
                                    class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">
                                    + Agregar fila
                                </button>
                                <button v-if="suggestionsApplied || form.entries.length > 0" type="button" @click="clearBulkSuggestions"
                                    class="px-3 py-1.5 border border-gray-300 text-gray-700 text-xs rounded hover:bg-gray-50">
                                    Limpiar
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="suggestionsApplied" class="mb-3 bg-amber-50 border border-amber-200 rounded-lg p-2 text-xs text-amber-800 space-y-1">
                        <p>
                            Se cargaron <strong>{{ form.entries.length }}</strong> fila(s) para <strong>{{ eligibleEmployeeCount }}</strong> empleado(s) con tiempo extra detectado.
                        </p>
                        <p>
                            Redondeo: &lt;30 min no cuenta · 30–49 min = 0.5h · 50 min en adelante = 1h (y así, sumando 0.5h en :30 y 1h completo en :50).
                        </p>
                    </div>

                    <div v-if="form.entries.length === 0" class="border rounded-lg p-6 text-center text-sm text-gray-500">
                        No hay filas todavía. Define un rango y carga desde checadas, o agrega una manualmente.
                    </div>

                    <div v-else class="max-h-[32rem] overflow-y-auto space-y-3 pr-1">
                        <div v-for="group in entriesGroupedByEmployee" :key="group.employee_id"
                            class="border rounded-lg overflow-hidden">
                            <!-- Employee header -->
                            <div class="bg-gray-50 px-4 py-2 flex items-center justify-between border-b">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900 truncate">
                                        {{ group.employee_name }}
                                        <span class="ml-2 text-xs font-normal text-gray-500">{{ group.employee_number }}</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-700 whitespace-nowrap">
                                    {{ group.entries.length }} día(s) · Total <strong>{{ group.total_hours }}h</strong>
                                </div>
                            </div>
                            <!-- Column headers shown once per group -->
                            <div class="bg-gray-50 px-4 py-1 grid grid-cols-12 gap-2 text-[10px] font-medium uppercase tracking-wide text-gray-500 border-b">
                                <div class="col-span-3">Día</div>
                                <div class="col-span-4">Inicio</div>
                                <div class="col-span-4">Fin</div>
                                <div class="col-span-1">h</div>
                            </div>
                            <!-- Day rows for this employee -->
                            <div class="divide-y divide-gray-100">
                                <div v-for="entry in group.entries" :key="`${entry.employee_id}_${entry.date}_${entry.kind}_${entry._index}`"
                                    class="px-4 py-2 grid grid-cols-12 gap-2 items-center text-sm bg-white">
                                    <div class="col-span-3 min-w-0">
                                        <div class="text-xs font-semibold text-pink-700">{{ formatDateShort(entry.date) }}</div>
                                        <div v-if="entry.summary" class="text-[10px] text-amber-700 truncate" :title="entry.summary">
                                            {{ entry.summary }}
                                        </div>
                                        <button type="button" @click="removeEntry(entry._index)"
                                            class="text-[10px] text-gray-400 hover:text-red-600">
                                            Quitar
                                        </button>
                                    </div>
                                    <input type="datetime-local"
                                        :value="getEntryDatetime(entry, 'start_time')"
                                        @input="setEntryDatetime(entry._index, 'start_time', $event.target.value)"
                                        class="col-span-4 rounded border-gray-300 text-xs focus:border-pink-500 focus:ring-pink-500" />
                                    <input type="datetime-local"
                                        :value="getEntryDatetime(entry, 'end_time')"
                                        @input="setEntryDatetime(entry._index, 'end_time', $event.target.value)"
                                        class="col-span-4 rounded border-gray-300 text-xs focus:border-pink-500 focus:ring-pink-500" />
                                    <input type="text" readonly
                                        :value="entry.hours"
                                        title="Calculado automáticamente desde inicio/fin con la regla escalonada"
                                        class="col-span-1 rounded border-gray-200 bg-gray-50 text-xs text-gray-700 text-right cursor-not-allowed" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3 (legacy): Date & Time for per_day / one_time -->
                <div v-if="selectedApplicationMode && !isPerHour && form.employee_ids.length > 0" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">3</span>
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
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Razon / Motivo *
                        </label>
                        <textarea
                            v-model="form.reason"
                            rows="3"
                            placeholder="Describa el motivo de esta autorizacion (aplica para todas las filas)..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Summary -->
                <div v-if="submitButtonCount > 0" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-800">
                        Se crearán <strong>{{ submitButtonCount }}</strong> autorización(es)
                        <span v-if="selectedTypeLabel"> de tipo <strong>{{ selectedTypeLabel }}</strong></span>
                    </p>
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
                        :disabled="!canSubmit"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creando...' : `Crear ${submitButtonCount} Autorizaciones` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
