<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
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

// Bulk edit state
const selectedEmployees = ref([]);
const bulkEditMode = ref(false);
const bulkField = ref('');
const bulkValue = ref('');

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
    if (!bulkField.value || !bulkValue.value || selectedEmployees.value.length === 0) {
        alert('Selecciona empleados, campo y valor para la edicion masiva');
        return;
    }
    if (confirm(`¿Actualizar ${selectedEmployees.value.length} empleados?`)) {
        router.post(route('employees.bulkUpdate'), {
            employee_ids: selectedEmployees.value,
            field: bulkField.value,
            value: bulkValue.value,
        }, {
            onSuccess: () => {
                selectedEmployees.value = [];
                bulkEditMode.value = false;
                bulkField.value = '';
                bulkValue.value = '';
            }
        });
    }
};

const cancelBulkEdit = () => {
    selectedEmployees.value = [];
    bulkEditMode.value = false;
    bulkField.value = '';
    bulkValue.value = '';
};

const applyFilters = debounce(() => {
    router.get(route('employees.index'), {
        search: search.value || undefined,
        department: department.value || undefined,
        position: position.value || undefined,
        schedule: schedule.value || undefined,
        supervisor: supervisor.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, department, position, schedule, supervisor, status], applyFilters);

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
    if (confirm(`¿Estás seguro de eliminar a ${employee.full_name}?`)) {
        router.delete(route('employees.destroy', employee.id));
    }
};
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
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-sm font-medium text-blue-800">
                    {{ selectedEmployees.length }} empleados seleccionados
                </span>
                <select
                    v-model="bulkField"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                >
                    <option value="">Seleccionar campo...</option>
                    <option value="department_id">Departamento</option>
                    <option value="status">Estado</option>
                    <option value="schedule_id">Horario</option>
                </select>
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
                    v-if="bulkField === 'status'"
                    v-model="bulkValue"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                >
                    <option value="">Seleccionar estado...</option>
                    <option value="active">Activo</option>
                    <option value="inactive">Inactivo</option>
                    <option value="terminated">Baja</option>
                </select>
                <button
                    @click="applyBulkEdit"
                    :disabled="selectedEmployees.length === 0 || !bulkField || !bulkValue"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
                >
                    Aplicar
                </button>
                <button
                    @click="cancelBulkEdit"
                    class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm"
                >
                    Cancelar
                </button>
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
                <div class="flex items-end">
                    <button
                        @click="search = ''; department = ''; position = ''; schedule = ''; supervisor = ''; status = '';"
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
                                    <div class="text-sm font-medium text-gray-900">{{ employee.full_name }}</div>
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
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
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
