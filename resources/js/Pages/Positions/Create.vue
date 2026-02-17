<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    departments: Array,
    schedules: Array,
    positions: Array,
    compensationTypes: Array,
});

const form = useForm({
    name: '',
    code: '',
    description: '',
    position_type: '',
    base_hourly_rate: '',
    default_overtime_rate: 1.5,
    default_holiday_rate: 2.0,
    is_active: true,
    department_id: '',
    supervisor_position_id: '',
    default_schedule_id: '',
    compensation_type_ids: [],
    compensation_type_percentages: {},
    compensation_type_fixed_amounts: {},
});

const positionTypeLabels = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    gerencial: 'Gerencial',
    directivo: 'Directivo',
};

const toggleCompensationType = (typeId) => {
    const idx = form.compensation_type_ids.indexOf(typeId);
    if (idx > -1) {
        form.compensation_type_ids.splice(idx, 1);
        delete form.compensation_type_percentages[typeId];
        delete form.compensation_type_fixed_amounts[typeId];
    } else {
        form.compensation_type_ids.push(typeId);
    }
};

const isCompensationTypeSelected = (typeId) => {
    return form.compensation_type_ids.includes(typeId);
};

const submit = () => {
    form.post(route('positions.store'));
};
</script>

<template>
    <Head title="Nuevo Puesto" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Puesto
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('positions.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a puestos
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Basic Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
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
                                Tipo de Puesto *
                            </label>
                            <select
                                v-model="form.position_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.position_type }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="(label, value) in positionTypeLabels" :key="value" :value="value">
                                    {{ label }}
                                </option>
                            </select>
                            <p v-if="form.errors.position_type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.position_type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Departamento *
                            </label>
                            <select
                                v-model="form.department_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.department_id }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.department_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.department_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Puesto Supervisor
                            </label>
                            <select
                                v-model="form.supervisor_position_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.supervisor_position_id }"
                            >
                                <option value="">Sin supervisor</option>
                                <option v-for="pos in positions" :key="pos.id" :value="pos.id">
                                    {{ pos.name }}
                                </option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Puesto al que reporta este puesto
                            </p>
                            <p v-if="form.errors.supervisor_position_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.supervisor_position_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Horario por Defecto
                            </label>
                            <select
                                v-model="form.default_schedule_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.default_schedule_id }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="sched in schedules" :key="sched.id" :value="sched.id">
                                    {{ sched.name }}
                                </option>
                            </select>
                            <p v-if="form.errors.default_schedule_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.default_schedule_id }}
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

                <!-- Compensation Rates -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tarifas de Compensacion</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tarifa Base por Hora (MXN) *
                            </label>
                            <input
                                v-model="form.base_hourly_rate"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.base_hourly_rate }"
                            />
                            <p v-if="form.errors.base_hourly_rate" class="mt-1 text-sm text-red-600">
                                {{ form.errors.base_hourly_rate }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Multiplicador Hora Extra
                            </label>
                            <input
                                v-model="form.default_overtime_rate"
                                type="number"
                                step="0.1"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.default_overtime_rate }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 1.5 = 50% extra
                            </p>
                            <p v-if="form.errors.default_overtime_rate" class="mt-1 text-sm text-red-600">
                                {{ form.errors.default_overtime_rate }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Multiplicador Dia Festivo
                            </label>
                            <input
                                v-model="form.default_holiday_rate"
                                type="number"
                                step="0.1"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.default_holiday_rate }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 2.0 = doble pago
                            </p>
                            <p v-if="form.errors.default_holiday_rate" class="mt-1 text-sm text-red-600">
                                {{ form.errors.default_holiday_rate }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Compensation Types -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Conceptos de Compensacion del Template</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Selecciona los conceptos de compensacion que aplican para este puesto. Opcionalmente puedes definir un valor especifico.
                    </p>

                    <div v-if="compensationTypes.length === 0" class="text-center py-8 text-gray-500">
                        No hay conceptos de compensacion registrados
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="ct in compensationTypes"
                            :key="ct.id"
                            class="flex items-center justify-between p-3 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
                            :class="{ 'border-pink-300 bg-pink-50': isCompensationTypeSelected(ct.id) }"
                        >
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    :checked="isCompensationTypeSelected(ct.id)"
                                    @change="toggleCompensationType(ct.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ ct.name }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ ct.code }} -
                                        <template v-if="ct.calculation_type === 'fixed'">Monto fijo: ${{ Number(ct.fixed_amount).toFixed(2) }}</template>
                                        <template v-else>Porcentaje base: {{ Number(ct.percentage_value).toFixed(2) }}%</template>
                                    </p>
                                </div>
                            </div>
                            <div v-if="isCompensationTypeSelected(ct.id)" class="flex items-center space-x-2">
                                <template v-if="ct.calculation_type === 'fixed'">
                                    <label class="text-xs text-gray-500">Monto especifico:</label>
                                    <input
                                        v-model="form.compensation_type_fixed_amounts[ct.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="ct.fixed_amount || '0.00'"
                                        class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                                <template v-else>
                                    <label class="text-xs text-gray-500">% especifico:</label>
                                    <input
                                        v-model="form.compensation_type_percentages[ct.id]"
                                        type="number"
                                        step="0.01"
                                        :placeholder="ct.percentage_value"
                                        class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    />
                                </template>
                            </div>
                        </div>
                    </div>
                    <p v-if="form.errors.compensation_type_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.compensation_type_ids }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('positions.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Puesto' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
