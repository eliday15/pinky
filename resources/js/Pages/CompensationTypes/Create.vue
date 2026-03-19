<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    positions: Array,
    departments: Array,
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
    priority: 0,
    position_ids: [],
    position_percentages: {},
    position_fixed_amounts: {},
    department_ids: [],
    department_percentages: {},
    department_fixed_amounts: {},
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

const togglePosition = (posId) => {
    const idx = form.position_ids.indexOf(posId);
    if (idx > -1) {
        form.position_ids.splice(idx, 1);
        delete form.position_percentages[posId];
        delete form.position_fixed_amounts[posId];
    } else {
        form.position_ids.push(posId);
    }
};

const toggleDepartment = (deptId) => {
    const idx = form.department_ids.indexOf(deptId);
    if (idx > -1) {
        form.department_ids.splice(idx, 1);
        delete form.department_percentages[deptId];
        delete form.department_fixed_amounts[deptId];
    } else {
        form.department_ids.push(deptId);
    }
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

                        <!-- Calculation Type -->
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

                        <!-- Percentage Value (shown when calculation_type is percentage) -->
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

                        <!-- Fixed Amount (shown when calculation_type is fixed) -->
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

                        <!-- Application Mode -->
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

                        <!-- Authorization Type -->
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

                        <!-- Priority -->
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

                <!-- Position Assignments -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Puestos Asignados</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Asigna este concepto a puestos especificos. Opcionalmente define un valor diferente por puesto.
                    </p>

                    <div v-if="positions.length === 0" class="text-center py-6 text-gray-500">
                        No hay puestos activos registrados
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="pos in positions"
                            :key="pos.id"
                            class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                            :class="{ 'border-pink-300 bg-pink-50': form.position_ids.includes(pos.id) }"
                        >
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    :checked="form.position_ids.includes(pos.id)"
                                    @change="togglePosition(pos.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ pos.name }}</p>
                                    <p class="text-xs text-gray-500">{{ pos.code }}</p>
                                </div>
                            </div>
                            <div v-if="form.position_ids.includes(pos.id)" class="flex items-center space-x-2">
                                <template v-if="form.calculation_type === 'percentage'">
                                    <label class="text-xs text-gray-500">% especifico:</label>
                                    <input
                                        v-model="form.position_percentages[pos.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="form.percentage_value || '0.00'"
                                        class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                                <template v-else>
                                    <label class="text-xs text-gray-500">Monto especifico:</label>
                                    <input
                                        v-model="form.position_fixed_amounts[pos.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="form.fixed_amount || '0.00'"
                                        class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Department Assignments -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Departamentos Asignados</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Asigna este concepto a departamentos especificos. Opcionalmente define un valor diferente por departamento.
                    </p>

                    <div v-if="departments.length === 0" class="text-center py-6 text-gray-500">
                        No hay departamentos activos registrados
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="dept in departments"
                            :key="dept.id"
                            class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                            :class="{ 'border-pink-300 bg-pink-50': form.department_ids.includes(dept.id) }"
                        >
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    :checked="form.department_ids.includes(dept.id)"
                                    @change="toggleDepartment(dept.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ dept.name }}</p>
                                    <p class="text-xs text-gray-500">{{ dept.code }}</p>
                                </div>
                            </div>
                            <div v-if="form.department_ids.includes(dept.id)" class="flex items-center space-x-2">
                                <template v-if="form.calculation_type === 'percentage'">
                                    <label class="text-xs text-gray-500">% especifico:</label>
                                    <input
                                        v-model="form.department_percentages[dept.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="form.percentage_value || '0.00'"
                                        class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                                <template v-else>
                                    <label class="text-xs text-gray-500">Monto especifico:</label>
                                    <input
                                        v-model="form.department_fixed_amounts[dept.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="form.fixed_amount || '0.00'"
                                        class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>
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
