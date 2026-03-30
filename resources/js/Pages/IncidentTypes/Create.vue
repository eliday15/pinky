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
    category: 'absence',
    is_paid: false,
    deducts_vacation: false,
    requires_approval: true,
    requires_document: false,
    affects_attendance: false,
    has_time_range: false,
    color: '#6B7280',
    is_active: true,
    priority: 0,
    position_ids: [],
    department_ids: [],
});

const categoryOptions = [
    { value: 'vacation', label: 'Vacaciones' },
    { value: 'sick_leave', label: 'Incapacidad' },
    { value: 'permission', label: 'Permiso' },
    { value: 'absence', label: 'Ausencia' },
    { value: 'late_accumulation', label: 'Acumulacion de Retardos' },
    { value: 'special', label: 'Especial' },
];

const togglePosition = (posId) => {
    const idx = form.position_ids.indexOf(posId);
    if (idx > -1) {
        form.position_ids.splice(idx, 1);
    } else {
        form.position_ids.push(posId);
    }
};

const toggleDepartment = (deptId) => {
    const idx = form.department_ids.indexOf(deptId);
    if (idx > -1) {
        form.department_ids.splice(idx, 1);
    } else {
        form.department_ids.push(deptId);
    }
};

const submit = () => {
    form.post(route('incident-types.store'));
};
</script>

<template>
    <Head title="Nuevo Tipo de Incidencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Tipo de Incidencia
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('incident-types.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a tipos de incidencia
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Informacion del Tipo -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion del Tipo</h3>
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Categoria *
                            </label>
                            <select
                                v-model="form.category"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.category }"
                            >
                                <option v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.category" class="mt-1 text-sm text-red-600">
                                {{ form.errors.category }}
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
                            <p v-if="form.errors.priority" class="mt-1 text-sm text-red-600">
                                {{ form.errors.priority }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Color
                            </label>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="form.color"
                                    type="color"
                                    class="h-10 w-16 rounded-lg border-gray-300 shadow-sm cursor-pointer"
                                />
                                <span class="text-sm text-gray-500">{{ form.color }}</span>
                            </div>
                            <p v-if="form.errors.color" class="mt-1 text-sm text-red-600">
                                {{ form.errors.color }}
                            </p>
                        </div>

                        <div class="flex items-end">
                            <label class="flex items-center space-x-3">
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm font-medium text-gray-700">Tipo activo</span>
                            </label>
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

                <!-- Reglas -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Reglas</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.is_paid"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Con goce de sueldo</span>
                                <p class="text-xs text-gray-500">El empleado sigue percibiendo salario</p>
                            </div>
                        </label>

                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.deducts_vacation"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Descuenta vacaciones</span>
                                <p class="text-xs text-gray-500">Se descuenta del saldo vacacional</p>
                            </div>
                        </label>

                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.requires_approval"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Requiere aprobacion</span>
                                <p class="text-xs text-gray-500">Necesita ser aprobado por un supervisor</p>
                            </div>
                        </label>

                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.requires_document"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Requiere documento</span>
                                <p class="text-xs text-gray-500">Se debe adjuntar un comprobante</p>
                            </div>
                        </label>

                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.affects_attendance"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Afecta asistencia</span>
                                <p class="text-xs text-gray-500">Impacta el registro de asistencia</p>
                            </div>
                        </label>

                        <label class="flex items-center space-x-3">
                            <input
                                v-model="form.has_time_range"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <div>
                                <span class="text-sm font-medium text-gray-700">Maneja rango de horas</span>
                                <p class="text-xs text-gray-500">Permite especificar hora de inicio y fin</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Puestos Asignados -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Puestos Asignados</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Asigna este tipo de incidencia a puestos especificos.
                    </p>

                    <div v-if="positions.length === 0" class="text-center py-6 text-gray-500">
                        No hay puestos activos registrados
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="pos in positions"
                            :key="pos.id"
                            class="flex items-center p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                            :class="{ 'border-pink-300 bg-pink-50': form.position_ids.includes(pos.id) }"
                        >
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
                    </div>
                </div>

                <!-- Departamentos Asignados -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Departamentos Asignados</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Asigna este tipo de incidencia a departamentos especificos.
                    </p>

                    <div v-if="departments.length === 0" class="text-center py-6 text-gray-500">
                        No hay departamentos activos registrados
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="dept in departments"
                            :key="dept.id"
                            class="flex items-center p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                            :class="{ 'border-pink-300 bg-pink-50': form.department_ids.includes(dept.id) }"
                        >
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
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('incident-types.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Tipo' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
