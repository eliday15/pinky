<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    employees: Object,
    departments: Array,
    positions: Array,
    schedules: Array,
    supervisors: Array,
    filters: Object,
    can: Object,
});

const search = ref(props.filters.search || '');
const department = ref(props.filters.department || '');
const position = ref(props.filters.position || '');
const schedule = ref(props.filters.schedule || '');
const supervisor = ref(props.filters.supervisor || '');
const status = ref(props.filters.status || '');
const isMinimumWage = ref(props.filters.is_minimum_wage || '');

// Bulk edit state
const selectedEmployees = ref([]);
const bulkEditMode = ref(false);
const operationType = ref('set_field');
const bulkField = ref('');
const bulkValue = ref('');
const compensationField = ref('');
const adjustmentType = ref('fixed');
const adjustmentValue = ref('');

const toggleSelectAll = (event) => {
    if (event.target.checked) {
        selectedEmployees.value = props.employees.data.map(e => e.id);
    } else {
        selectedEmployees.value = [];
    }
};

const toggleEmployee = (id) => {
    const idx = selectedEmployees.value.indexOf(id);
    if (idx > -1) {
        selectedEmployees.value.splice(idx, 1);
    } else {
        selectedEmployees.value.push(id);
    }
};

const applyBulkEdit = () => {
    if (selectedEmployees.value.length === 0) {
        alert('Selecciona al menos un empleado para la edicion masiva');
        return;
    }

    if (operationType.value === 'set_field' && (!bulkField.value || !bulkValue.value)) {
        alert('Selecciona campo y valor para la edicion masiva');
        return;
    }

    if (operationType.value === 'adjust_compensation' && (!compensationField.value || !adjustmentValue.value)) {
        alert('Selecciona campo de compensacion y valor de ajuste');
        return;
    }

    if (confirm(`¿Actualizar ${selectedEmployees.value.length} empleados?`)) {
        router.post(route('employees.bulkUpdate'), {
            employee_ids: selectedEmployees.value,
            operation_type: operationType.value,
            field: bulkField.value,
            value: bulkValue.value,
            compensation_field: compensationField.value,
            adjustment_type: adjustmentType.value,
            adjustment_value: adjustmentValue.value,
        }, {
            onSuccess: () => {
                selectedEmployees.value = [];
                bulkEditMode.value = false;
                operationType.value = 'set_field';
                bulkField.value = '';
                bulkValue.value = '';
                compensationField.value = '';
                adjustmentType.value = 'fixed';
                adjustmentValue.value = '';
            }
        });
    }
};

const cancelBulkEdit = () => {
    selectedEmployees.value = [];
    bulkEditMode.value = false;
    operationType.value = 'set_field';
    bulkField.value = '';
    bulkValue.value = '';
    compensationField.value = '';
    adjustmentType.value = 'fixed';
    adjustmentValue.value = '';
};

const applyFilters = debounce(() => {
    router.get(route('employees.index'), {
        search: search.value || undefined,
        department: department.value || undefined,
        position: position.value || undefined,
        schedule: schedule.value || undefined,
        supervisor: supervisor.value || undefined,
        status: status.value || undefined,
        is_minimum_wage: isMinimumWage.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, department, position, schedule, supervisor, status, isMinimumWage], applyFilters);

const statusColors = {
    active: 'bg-green-100 text-green-800',
    inactive: 'bg-yellow-100 text-yellow-800',
    terminated: 'bg-red-100 text-red-800',
};

const statusLabels = {
    active: 'Activo',
    inactive: 'Inactivo',
    terminated: 'Baja',
};

const deleteEmployee = (employee) => {
    if (confirm(`¿Estas seguro de eliminar a ${employee.full_name}?`)) {
        router.delete(route('employees.destroy', employee.id));
    }
};

const clearFilters = () => {
    search.value = '';
    department.value = '';
    position.value = '';
    schedule.value = '';
    supervisor.value = '';
    status.value = '';
    isMinimumWage.value = '';
};

const exportUrl = computed(() => {
    const params = new URLSearchParams();
    if (department.value) params.append('department', department.value);
    if (status.value) params.append('status', status.value);
    if (isMinimumWage.value) params.append('is_minimum_wage', isMinimumWage.value);
    if (bulkEditMode.value && selectedEmployees.value.length > 0) {
        selectedEmployees.value.forEach(id => params.append('employee_ids[]', id));
    }
    const qs = params.toString();
    return route('employees.export') + (qs ? '?' + qs : '');
});
</script>

<template>
    <Head title="Empleados" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Empleados
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion de Empleados</h1>
                <p class="text-gray-600">{{ employees.total }} empleados registrados</p>
            </div>
            <div class="flex gap-2">
                <a
                    v-if="can?.bulkEdit"
                    :href="exportUrl"
                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Exportar Excel
                </a>
                <Link
                    v-if="can?.bulkEdit"
                    :href="route('employees.import')"
                    class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                    </svg>
                    Importar Excel
                </Link>
                <button
                    v-if="can?.bulkEdit && !bulkEditMode"
                    @click="bulkEditMode = true"
                    class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Edicion Masiva
                </button>
                <Link
                    v-if="can?.create"
                    :href="route('employees.create')"
                    class="inline-flex items-center px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Nuevo Empleado
                </Link>
            </div>
        </div>

        <!-- Bulk Edit Panel -->
        <div v-if="bulkEditMode" class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium text-blue-800">
                        {{ selectedEmployees.length }} empleados seleccionados
                    </span>
                    <button
                        @click="cancelBulkEdit"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm"
                    >
                        Cancelar
                    </button>
                </div>

                <!-- Operation Type Selector -->
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <label class="block text-xs font-medium text-blue-700 mb-1">Tipo de operacion</label>
                        <select
                            v-model="operationType"
                            class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                        >
                            <option value="set_field">Asignar campo</option>
                            <option value="adjust_compensation">Ajustar compensacion</option>
                        </select>
                    </div>

                    <!-- Set Field Options -->
                    <template v-if="operationType === 'set_field'">
                        <div>
                            <label class="block text-xs font-medium text-blue-700 mb-1">Campo</label>
                            <select
                                v-model="bulkField"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="">Seleccionar campo...</option>
                                <option value="department_id">Departamento</option>
                                <option value="status">Estado</option>
                                <option value="schedule_id">Horario</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-blue-700 mb-1">Valor</label>
                            <select
                                v-if="bulkField === 'department_id'"
                                v-model="bulkValue"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="">Seleccionar departamento...</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                            <select
                                v-else-if="bulkField === 'status'"
                                v-model="bulkValue"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="">Seleccionar estado...</option>
                                <option value="active">Activo</option>
                                <option value="inactive">Inactivo</option>
                                <option value="terminated">Baja</option>
                            </select>
                            <select
                                v-else-if="bulkField === 'schedule_id'"
                                v-model="bulkValue"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="">Seleccionar horario...</option>
                                <option v-for="sch in schedules" :key="sch.id" :value="sch.id">
                                    {{ sch.name }}
                                </option>
                            </select>
                            <input
                                v-else
                                v-model="bulkValue"
                                type="text"
                                placeholder="Seleccionar campo primero..."
                                disabled
                                class="rounded-lg border-gray-300 shadow-sm text-sm bg-gray-100"
                            />
                        </div>
                    </template>

                    <!-- Adjust Compensation Options -->
                    <template v-if="operationType === 'adjust_compensation'">
                        <div>
                            <label class="block text-xs font-medium text-blue-700 mb-1">Campo de compensacion</label>
                            <select
                                v-model="compensationField"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="">Seleccionar...</option>
                                <option value="hourly_rate">Tarifa por hora</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-blue-700 mb-1">Tipo de ajuste</label>
                            <select
                                v-model="adjustmentType"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            >
                                <option value="fixed">Valor fijo</option>
                                <option value="percentage">Porcentaje (%)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-blue-700 mb-1">
                                Valor {{ adjustmentType === 'percentage' ? '(%)' : '' }}
                            </label>
                            <input
                                v-model="adjustmentValue"
                                type="number"
                                step="0.01"
                                :placeholder="adjustmentType === 'percentage' ? 'Ej: 10 para +10%' : 'Nuevo valor'"
                                class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            />
                        </div>
                    </template>

                    <div class="flex items-end">
                        <button
                            @click="applyBulkEdit"
                            :disabled="selectedEmployees.length === 0"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
                        >
                            Aplicar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre, numero, email..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                    <select
                        v-model="department"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Puesto</label>
                    <select
                        v-model="position"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="pos in positions" :key="pos.id" :value="pos.id">
                            {{ pos.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Horario</label>
                    <select
                        v-model="schedule"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="sch in schedules" :key="sch.id" :value="sch.id">
                            {{ sch.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jefe</label>
                    <select
                        v-model="supervisor"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="sup in supervisors" :key="sup.id" :value="sup.id">
                            {{ sup.full_name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select
                        v-model="status"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Solo Activos</option>
                        <option value="all">Todos</option>
                        <option value="inactive">Solo Inactivos</option>
                        <option value="terminated">Solo Baja</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Salario Minimo</label>
                    <select
                        v-model="isMinimumWage"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option value="yes">Solo Salario Minimo</option>
                        <option value="no">Solo Arriba del Minimo</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button
                        @click="clearFilters"
                        class="w-full px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                    >
                        Limpiar filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th v-if="bulkEditMode" class="px-4 py-3 text-left">
                            <input
                                type="checkbox"
                                @change="toggleSelectAll"
                                :checked="selectedEmployees.length === employees.data.length && employees.data.length > 0"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Empleado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Puesto
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Horario
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="employee in employees.data" :key="employee.id" class="hover:bg-gray-50">
                        <td v-if="bulkEditMode" class="px-4 py-4">
                            <input
                                type="checkbox"
                                :checked="selectedEmployees.includes(employee.id)"
                                @change="toggleEmployee(employee.id)"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-10 h-10 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 font-medium">
                                        {{ employee.full_name?.charAt(0)?.toUpperCase() || '?' }}
                                    </span>
                                </div>
                                <div class="ml-4">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-gray-900">{{ employee.full_name }}</span>
                                        <span v-if="employee.is_minimum_wage" class="px-1.5 py-0.5 text-xs font-medium rounded bg-orange-100 text-orange-700">
                                            SM
                                        </span>
                                        <span v-if="employee.is_trial_period" class="px-1.5 py-0.5 text-xs font-medium rounded bg-amber-100 text-amber-700">
                                            Prueba
                                        </span>
                                        <span v-if="!employee.schedule_id || !employee.supervisor_id" class="px-1.5 py-0.5 text-xs font-medium rounded bg-red-100 text-red-700">
                                            Incompleto
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-500">{{ employee.employee_number }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ employee.department?.name || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ employee.position?.name || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ employee.schedule?.name || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[employee.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[employee.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <Link
                                :href="route('employees.show', employee.id)"
                                class="text-gray-600 hover:text-gray-900 mr-3"
                            >
                                Ver
                            </Link>
                            <Link
                                :href="route('employees.edit', employee.id)"
                                class="text-pink-600 hover:text-pink-900 mr-3"
                            >
                                Editar
                            </Link>
                            <button
                                @click="deleteEmployee(employee)"
                                class="text-red-600 hover:text-red-900"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="employees.data.length === 0">
                        <td :colspan="bulkEditMode ? 7 : 6" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron empleados
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="employees.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ employees.from }} a {{ employees.to }} de {{ employees.total }} resultados
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in employees.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active
                                    ? 'bg-pink-600 text-white'
                                    : link.url
                                        ? 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                        : 'bg-gray-50 text-gray-400 cursor-not-allowed'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
