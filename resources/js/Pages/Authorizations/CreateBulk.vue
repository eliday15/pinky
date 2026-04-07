<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { todayLocal } from '@/utils/date';

const props = defineProps({
    employees: Array,
    types: Array,
    departments: Array,
    departmentHeads: Array,
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
    department_head_id: '',
});

/** The application_mode of the currently selected compensation type. */
const selectedApplicationMode = computed(() => {
    if (!form.compensation_type_id) return null;
    const t = props.types.find(t => t.compensation_type_id === form.compensation_type_id);
    return t?.application_mode || null;
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
    let employees = props.employees;

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

    return employees;
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

/** Group types for optgroup display, filtered by intersection of selected employees' active compensation types. */
const compensationTypes = computed(() => {
    const all = props.types.filter(t => t.group === 'compensation');
    if (form.employee_ids.length === 0) return all;
    const selectedEmps = props.employees.filter(e => form.employee_ids.includes(e.id));
    return all.filter(t => selectedEmps.every(emp =>
        emp.active_compensation_type_ids?.includes(t.compensation_type_id)
    ));
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

/** Reset type selection when employee selection changes and selected type is no longer available. */
watch(() => form.employee_ids.length, () => {
    if (form.compensation_type_id) {
        const stillValid = compensationTypes.value.some(t => t.compensation_type_id === form.compensation_type_id);
        if (!stillValid) {
            form.type = '';
            form.compensation_type_id = null;
        }
    }
});

/** Filtered department heads based on selected department. */
const filteredDepartmentHeads = computed(() => {
    if (!departmentFilter.value) return props.departmentHeads;
    return props.departmentHeads.filter(dh => dh.department_id == departmentFilter.value);
});

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

                <!-- Employee Selection -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-3">
                        <h3 class="text-lg font-semibold text-gray-800">
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
                                No se encontraron empleados
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.employee_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.employee_ids }}
                    </p>
                </div>

                <!-- Authorization Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tipo de Autorizacion</h3>
                    <div>
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

                    <!-- Night shift info banner -->
                    <div v-if="form.type === 'night_shift'" class="mt-4 bg-indigo-50 border border-indigo-200 rounded-lg p-3">
                        <p class="text-sm text-indigo-800">
                            <strong>Velada:</strong> Las horas de inicio y fin se han pre-configurado para turno nocturno (22:00 - 06:00).
                            Puede ajustarlos si es necesario.
                        </p>
                    </div>
                </div>

                <!-- Date & Time - adapts to application_mode -->
                <div v-if="selectedApplicationMode" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fecha y Horario</h3>

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
                            <p class="mt-1 text-xs text-gray-500">Numero de unidades (el monto se calcula del valor asignado al empleado)</p>
                        </div>
                    </div>
                </div>

                <!-- Department Head -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Jefe de Departamento</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Firmado por (opcional)
                        </label>
                        <select
                            v-model="form.department_head_id"
                            class="w-full md:w-1/2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        >
                            <option value="">Sin firma de jefe de departamento</option>
                            <option v-for="head in filteredDepartmentHeads" :key="head.id" :value="head.id">
                                {{ head.full_name }}
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">
                            Seleccione el jefe de departamento que autoriza esta operacion masiva
                        </p>
                        <p v-if="form.errors.department_head_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.department_head_id }}
                        </p>
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
                        <span v-if="form.department_head_id"> firmadas por <strong>{{ filteredDepartmentHeads.find(h => h.id == form.department_head_id)?.full_name }}</strong></span>
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
