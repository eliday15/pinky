<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { todayLocal } from '@/utils/date';

const props = defineProps({
    employees: Array,
    types: Array,
    departments: Array,
});

const today = todayLocal();
const startDatetime = ref(`${today}T08:00`);
const endDatetime = ref(`${today}T16:00`);
const startDate = ref(today);
const endDate = ref(today);

const form = useForm({
    employee_ids: [],
    type: '',
    compensation_type_id: null,
    date: today,
    start_time: '',
    end_time: '',
    hours: '',
    reason: '',
    employee_times: {}, // { [employee_id]: { start_time, end_time, hours } }
});

/** The application_mode of the currently selected compensation type. */
const selectedApplicationMode = computed(() => {
    if (!form.compensation_type_id) return null;
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.application_mode || null;
});

/** Human-readable label of the selected type (e.g., "Hora Extra Triple"). */
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

const filteredEmployees = computed(() => {
    let employees = employeesForSelectedType.value;

    // Filter by department
    if (departmentFilter.value) {
        employees = employees.filter(emp => emp.department_id == departmentFilter.value);
    }

    // Filter by search query
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        employees = employees.filter(emp =>
            emp.full_name.toLowerCase().includes(query) ||
            emp.employee_number.toLowerCase().includes(query)
        );
    }

    // Pin selected employees at the top so the user sees their picks without scrolling.
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

/** Select all employees from a specific department. */
const selectDepartment = () => {
    if (!departmentFilter.value) return;
    form.employee_ids = filteredEmployees.value.map(e => e.id);
    selectAll.value = true;
};

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

const optionValue = (type) => {
    return `comp_${type.compensation_type_id}`;
};

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

/** Pre-set night shift defaults when type changes. */
watch(() => form.type, (newType) => {
    if (newType === 'night_shift' && !form.start_time && !form.end_time) {
        const dateStr = startDatetime.value.split('T')[0] || today;
        startDatetime.value = `${dateStr}T22:00`;
        // Next day for end
        const nextDay = new Date(dateStr);
        nextDay.setDate(nextDay.getDate() + 1);
        const nextDayStr = nextDay.toISOString().split('T')[0];
        endDatetime.value = `${nextDayStr}T06:00`;
    }
});

/** Reset select-all when department filter changes. */
watch(departmentFilter, () => {
    selectAll.value = false;
});

/** When the type changes, drop any selected employees who can't receive that type. */
watch(() => form.compensation_type_id, (newCompId) => {
    if (!newCompId) return;
    const allowedIds = new Set(employeesForSelectedType.value.map(e => e.id));
    form.employee_ids = form.employee_ids.filter(id => allowedIds.has(id));
    selectAll.value = false;
});

/** Reset cached suggestions whenever the inputs that drive them change. */
watch(
    () => [form.type, form.date, form.employee_ids.length],
    () => {
        if (suggestionsApplied.value || suggestions.value.length > 0) {
            suggestions.value = [];
            suggestionsApplied.value = false;
            form.employee_times = {};
        }
    },
);

/* ----- Bulk live suggestions from schedule + attendance ----- */
const suggestions = ref([]); // backend now only returns eligible (found=true) entries
const suggestionsLoading = ref(false);
const suggestionsApplied = ref(false);
const skippedCount = ref(0);

const canSuggestBulk = computed(() => {
    return form.employee_ids.length > 0
        && form.date
        && (form.type === 'overtime' || form.type === 'night_shift');
});

const totalSuggestedHours = computed(() => {
    return suggestions.value.reduce((sum, s) => sum + parseFloat(s.hours || 0), 0).toFixed(2);
});

const applyBulkSuggestions = () => {
    if (suggestions.value.length === 0) return;
    form.employee_ids = suggestions.value.map(s => s.employee_id);
    const map = {};
    for (const s of suggestions.value) {
        map[s.employee_id] = {
            start_time: s.start_time,
            end_time: s.end_time,
            hours: s.hours,
        };
    }
    form.employee_times = map;
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
                date: form.date,
                type: form.type,
            },
        });
        suggestions.value = data.suggestions || [];
        skippedCount.value = data.skipped_count || 0;
        // Auto-apply: the user already filtered to a specific date+type, so the
        // intent is clear. They can still "Limpiar" if they want manual entry.
        if (suggestions.value.length > 0) {
            applyBulkSuggestions();
        }
    } catch {
        suggestions.value = [];
        skippedCount.value = 0;
    } finally {
        suggestionsLoading.value = false;
    }
};

const clearBulkSuggestions = () => {
    suggestions.value = [];
    skippedCount.value = 0;
    suggestionsApplied.value = false;
    form.employee_times = {};
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

const getDepartmentName = (deptId) => {
    const dept = props.departments?.find(d => d.id == deptId);
    return dept ? dept.name : '';
};
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
            <!-- Breadcrumb -->
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

                    <!-- Night shift info banner -->
                    <div v-if="form.type === 'night_shift'" class="mt-4 bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                        <p class="text-sm text-indigo-800">
                            <strong>Velada:</strong> Las horas de inicio y fin se han pre-configurado para turno nocturno (22:00 - 06:00).
                            Puede ajustarlos si es necesario.
                        </p>
                    </div>
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
                            <!-- Department Filter -->
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
                        <!-- Header -->
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

                        <!-- Employee List -->
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

                <!-- Live suggestions from schedule + attendance -->
                <div v-if="(form.type === 'overtime' || form.type === 'night_shift') && form.employee_ids.length > 0" class="bg-amber-50 border-l-4 border-amber-400 rounded-lg p-4">
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="flex-1">
                            <h4 class="text-sm font-semibold text-amber-800">Sugerencia desde checadas reales</h4>
                            <p class="text-xs text-amber-700 mt-1">
                                Comparar horario vs checadas reales y pre-llenar horas individuales.
                            </p>
                            <div class="mt-2 flex items-center gap-2">
                                <label class="text-xs text-amber-700 font-medium">Fecha a revisar:</label>
                                <input type="date" v-model="form.date"
                                    class="text-xs rounded border-amber-300 focus:border-amber-500 focus:ring-amber-500 py-1" />
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button v-if="suggestions.length === 0"
                                type="button" @click="fetchBulkSuggestions"
                                :disabled="suggestionsLoading"
                                class="px-3 py-1.5 bg-amber-600 text-white text-xs rounded hover:bg-amber-700 disabled:opacity-50">
                                {{ suggestionsLoading ? 'Calculando...' : 'Cargar sugerencias' }}
                            </button>
                            <button v-else
                                type="button" @click="clearBulkSuggestions"
                                class="px-3 py-1.5 border border-amber-400 text-amber-700 text-xs rounded hover:bg-amber-100">
                                Limpiar
                            </button>
                        </div>
                    </div>

                    <div v-if="suggestions.length > 0" class="bg-white rounded-lg border border-amber-200 overflow-hidden">
                        <div class="px-4 py-2 bg-green-50 border-b border-green-200 text-xs text-green-800 flex items-center justify-between">
                            <span>
                                ✓ <strong>{{ suggestions.length }}</strong> autorizaciones listas con horas individuales
                                ({{ totalSuggestedHours }}h totales)
                                <span v-if="skippedCount > 0" class="text-gray-500 ml-2">
                                    • {{ skippedCount }} sin tiempo extra (omitidos)
                                </span>
                            </span>
                        </div>
                        <div class="max-h-72 overflow-y-auto divide-y divide-gray-100">
                            <div v-for="s in suggestions" :key="s.employee_id"
                                class="px-4 py-2 flex items-center justify-between text-sm bg-white">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium text-gray-900 truncate">{{ s.employee_name }}</div>
                                    <div class="text-xs text-amber-700">{{ s.summary }}</div>
                                </div>
                                <div class="text-right text-xs ml-3 flex-shrink-0">
                                    <div class="font-mono text-gray-700">{{ s.start_time }} - {{ s.end_time }}</div>
                                    <div class="font-semibold text-amber-700">{{ s.hours }}h</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="suggestionsApplied || (skippedCount > 0 && !suggestionsLoading)" class="bg-gray-50 rounded-lg p-3 text-sm text-gray-600">
                        No se encontraron empleados con tiempo extra para esa fecha.
                    </div>
                </div>

                <!-- Step 3: Date & Time - adapts to application_mode -->
                <div v-if="selectedApplicationMode && form.employee_ids.length > 0 && !suggestionsApplied" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-1">
                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-pink-600 text-white text-xs mr-2">3</span>
                        {{ dateCardTitle }}
                    </h3>
                    <p v-if="selectedTypeLabel" class="text-xs text-gray-500 mb-4">Aplicara como <strong>{{ selectedTypeLabel }}</strong></p>

                    <!-- per_hour -->
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

                    <!-- per_day -->
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
                            placeholder="Describa el motivo de esta autorizacion (aplica para todos los empleados seleccionados)..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Summary -->
                <div v-if="form.employee_ids.length > 0" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-800">
                        Se crearan <strong>{{ form.employee_ids.length }}</strong> autorizaciones
                        <span v-if="form.type"> de tipo <strong>{{ form.compensation_type_id ? types.find(t => t.compensation_type_id === form.compensation_type_id)?.label : types.find(t => t.value === form.type && !t.compensation_type_id)?.label }}</strong></span>
                        <span v-if="form.date"> para el <strong>{{ form.date }}</strong></span>
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
                        :disabled="form.processing || form.employee_ids.length === 0"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creando...' : `Crear ${form.employee_ids.length} Autorizaciones` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
