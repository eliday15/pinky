<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    positions: Array,
    departments: Array,
    employees: Array,
});

const form = useForm({
    name: '',
    code: '',
    description: '',
    calculation_type: 'percentage',
    percentage_value: '',
    fixed_amount: '',
    is_active: true,
    application_mode: 'per_hour',
    authorization_type: '',
    attendance_pull_rule: null,
    priority: 0,
    employee_ids: [],
    employee_percentages: {},
    employee_fixed_amounts: {},
});

const applicationModeOptions = [
    { value: 'per_hour', label: 'Por Hora' },
    { value: 'per_day', label: 'Por Dia' },
    { value: 'one_time', label: 'Monto Unico' },
];

const authorizationTypeOptions = [
    { value: '', label: 'Ninguno' },
    { value: 'overtime', label: 'Horas Extra' },
    { value: 'night_shift', label: 'Velada' },
    { value: 'holiday_worked', label: 'Dia Festivo' },
    { value: 'special', label: 'Especial' },
];

/* ---- Employee selection (department-filtered) ---- */
const departmentFilter = ref('');
const employeeSearch = ref('');

const departmentName = (deptId) => {
    const d = props.departments.find(d => d.id == deptId);
    return d ? d.name : '';
};

const filteredEmployees = computed(() => {
    let list = props.employees || [];
    if (departmentFilter.value) {
        list = list.filter(e => e.department_id == departmentFilter.value);
    }
    if (employeeSearch.value) {
        const q = employeeSearch.value.toLowerCase();
        list = list.filter(e =>
            (e.full_name || '').toLowerCase().includes(q) ||
            (e.employee_number || '').toLowerCase().includes(q)
        );
    }
    const selected = new Set(form.employee_ids);
    return [...list].sort((a, b) => {
        const sA = selected.has(a.id) ? 0 : 1;
        const sB = selected.has(b.id) ? 0 : 1;
        if (sA !== sB) return sA - sB;
        return (a.full_name || '').localeCompare(b.full_name || '');
    });
});

const visibleAllSelected = computed(() => {
    if (filteredEmployees.value.length === 0) return false;
    return filteredEmployees.value.every(e => form.employee_ids.includes(e.id));
});

const omitKey = (obj, key) => {
    const next = { ...obj };
    delete next[key];
    return next;
};

const toggleEmployee = (empId) => {
    if (form.employee_ids.includes(empId)) {
        form.employee_ids = form.employee_ids.filter(id => id !== empId);
        form.employee_percentages = omitKey(form.employee_percentages, empId);
        form.employee_fixed_amounts = omitKey(form.employee_fixed_amounts, empId);
    } else {
        form.employee_ids = [...form.employee_ids, empId];
    }
};

const toggleSelectAllVisible = () => {
    if (visibleAllSelected.value) {
        const visibleIds = new Set(filteredEmployees.value.map(e => e.id));
        form.employee_ids = form.employee_ids.filter(id => !visibleIds.has(id));
        let percentages = { ...form.employee_percentages };
        let fixed = { ...form.employee_fixed_amounts };
        visibleIds.forEach(id => {
            delete percentages[id];
            delete fixed[id];
        });
        form.employee_percentages = percentages;
        form.employee_fixed_amounts = fixed;
    } else {
        const merged = new Set(form.employee_ids);
        filteredEmployees.value.forEach(e => merged.add(e.id));
        form.employee_ids = [...merged];
    }
};

const clearAllEmployees = () => {
    form.employee_ids = [];
    form.employee_percentages = {};
    form.employee_fixed_amounts = {};
};

const submit = () => {
    form.post(route('compensation-types.store'));
};
</script>

<template>
    <Head title="Nuevo Concepto de Compensacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Concepto de Compensacion
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('compensation-types.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a conceptos de compensacion
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion del Concepto</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Nombre *
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo *
                            </label>
                            <input
                                v-model="form.code"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.code }"
                            />
                            <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                                {{ form.errors.code }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Tipo de Calculo *
                            </label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input
                                        v-model="form.calculation_type"
                                        type="radio"
                                        value="fixed"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Monto Fijo ($)</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        v-model="form.calculation_type"
                                        type="radio"
                                        value="percentage"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Porcentaje del salario (%)</span>
                                </label>
                            </div>
                            <p v-if="form.errors.calculation_type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.calculation_type }}
                            </p>
                        </div>

                        <div v-if="form.calculation_type === 'percentage'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Porcentaje del Salario (%) *
                            </label>
                            <input
                                v-model="form.percentage_value"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.percentage_value }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 50 = 50% del salario diario, 100 = salario completo
                            </p>
                            <p v-if="form.errors.percentage_value" class="mt-1 text-sm text-red-600">
                                {{ form.errors.percentage_value }}
                            </p>
                        </div>

                        <div v-if="form.calculation_type === 'fixed'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Monto Fijo (MXN) *
                            </label>
                            <input
                                v-model="form.fixed_amount"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.fixed_amount }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Monto fijo a pagar por este concepto
                            </p>
                            <p v-if="form.errors.fixed_amount" class="mt-1 text-sm text-red-600">
                                {{ form.errors.fixed_amount }}
                            </p>
                        </div>

                        <div class="flex items-end">
                            <label class="flex items-center space-x-3">
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm font-medium text-gray-700">Concepto activo</span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Modo de Aplicacion *
                            </label>
                            <div class="flex flex-wrap gap-3">
                                <label
                                    v-for="opt in applicationModeOptions"
                                    :key="opt.value"
                                    class="flex items-center"
                                >
                                    <input
                                        v-model="form.application_mode"
                                        type="radio"
                                        :value="opt.value"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">{{ opt.label }}</span>
                                </label>
                            </div>
                            <p v-if="form.errors.application_mode" class="mt-1 text-sm text-red-600">
                                {{ form.errors.application_mode }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion
                            </label>
                            <select
                                v-model="form.authorization_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.authorization_type }"
                            >
                                <option v-for="opt in authorizationTypeOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Vincula este concepto a un tipo de autorizacion
                            </p>
                            <p v-if="form.errors.authorization_type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.authorization_type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Jalar desde checadas
                            </label>
                            <select
                                v-model="form.attendance_pull_rule"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.attendance_pull_rule }"
                            >
                                <option :value="null">Ninguna</option>
                                <option value="meal">Cena (12h, velada o fin de semana)</option>
                                <option value="weekend">Fin de semana (trabajo sab/dom fuera de horario)</option>
                                <option value="comida">Comida (solo trabajo en fin de semana)</option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Al jalar desde checadas se generan entradas automaticas por cada dia que califique segun la regla elegida. "Cena": jornada minima, cruzo medianoche o fin de semana. "Fin de semana": trabajo en sabado/domingo fuera de su horario. "Comida": lunch solo por trabajar fin de semana. No se auto-aprueban.
                            </p>
                            <p v-if="form.errors.attendance_pull_rule" class="mt-1 text-sm text-red-600">
                                {{ form.errors.attendance_pull_rule }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Prioridad
                            </label>
                            <input
                                v-model="form.priority"
                                type="number"
                                min="0"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.priority }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Menor = mayor prioridad (ej: HE=10, HED=20, HET=30)
                            </p>
                            <p v-if="form.errors.priority" class="mt-1 text-sm text-red-600">
                                {{ form.errors.priority }}
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Descripcion
                            </label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.description }"
                            ></textarea>
                            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Employee Assignments -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-2 gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">
                                Empleados Asignados
                                <span class="text-sm font-normal text-gray-500 ml-2">
                                    ({{ form.employee_ids.length }} seleccionados)
                                </span>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Filtra por departamento, luego selecciona los empleados a los que aplica este concepto.
                            </p>
                        </div>
                        <button
                            v-if="form.employee_ids.length > 0"
                            type="button"
                            @click="clearAllEmployees"
                            class="px-3 py-2 text-xs border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 whitespace-nowrap"
                        >
                            Quitar todos
                        </button>
                    </div>

                    <div class="flex flex-col md:flex-row gap-3 mb-4">
                        <select
                            v-model="departmentFilter"
                            class="md:w-1/3 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        >
                            <option value="">Todos los departamentos</option>
                            <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                {{ dept.name }}
                            </option>
                        </select>
                        <input
                            v-model="employeeSearch"
                            type="text"
                            placeholder="Buscar empleado..."
                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        />
                    </div>

                    <div v-if="(employees || []).length === 0" class="text-center py-6 text-gray-500">
                        No hay empleados activos registrados
                    </div>

                    <div v-else class="border rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-3 border-b flex items-center">
                            <input
                                type="checkbox"
                                :checked="visibleAllSelected"
                                @change="toggleSelectAllVisible"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <span class="ml-3 text-sm font-medium text-gray-700">
                                Seleccionar todos los visibles
                            </span>
                            <span class="ml-2 text-xs text-gray-500">
                                ({{ filteredEmployees.length }} resultado{{ filteredEmployees.length === 1 ? '' : 's' }})
                            </span>
                        </div>

                        <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                            <div
                                v-for="emp in filteredEmployees"
                                :key="emp.id"
                                class="px-4 py-3 flex items-center justify-between hover:bg-gray-50 cursor-pointer"
                                :class="{ 'bg-pink-50': form.employee_ids.includes(emp.id) }"
                                @click="toggleEmployee(emp.id)"
                            >
                                <div class="flex items-center flex-1 min-w-0">
                                    <input
                                        type="checkbox"
                                        :checked="form.employee_ids.includes(emp.id)"
                                        class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                        @click.stop
                                        @change="toggleEmployee(emp.id)"
                                    />
                                    <div class="ml-3 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ emp.full_name }}</p>
                                        <p class="text-xs text-gray-500 truncate">
                                            {{ emp.employee_number }}
                                            <span v-if="emp.department_id" class="ml-1">
                                                - {{ departmentName(emp.department_id) }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-if="form.employee_ids.includes(emp.id)"
                                    class="flex items-center space-x-2 ml-3"
                                    @click.stop
                                >
                                    <template v-if="form.calculation_type === 'percentage'">
                                        <label class="text-xs text-gray-500">% especifico:</label>
                                        <input
                                            v-model="form.employee_percentages[emp.id]"
                                            type="number"
                                            step="0.01"
                                            :placeholder="form.percentage_value || '0.00'"
                                            class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                    <template v-else>
                                        <label class="text-xs text-gray-500">Monto:</label>
                                        <input
                                            v-model="form.employee_fixed_amounts[emp.id]"
                                            type="number"
                                            step="0.01"
                                            :placeholder="form.fixed_amount || '0.00'"
                                            class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                </div>
                            </div>
                            <div v-if="filteredEmployees.length === 0" class="px-4 py-8 text-center text-gray-500 text-sm">
                                No hay empleados que coincidan con el filtro.
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.employee_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.employee_ids }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('compensation-types.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Concepto' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
