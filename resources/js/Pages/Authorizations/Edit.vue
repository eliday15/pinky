<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    authorization: Object,
    employees: Array,
    types: Array,
});

// Format time for input fields
const formatTime = (time) => {
    if (!time) return '';
    // Handle both "HH:mm" and "HH:mm:ss" formats
    const parts = time.split(':');
    return parts.length >= 2 ? `${parts[0]}:${parts[1]}` : '';
};

// Format date for input fields
const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toISOString().split('T')[0];
};

const initDate = formatDate(props.authorization.date);
const initStartTime = formatTime(props.authorization.start_time) || '08:00';
const initEndTime = formatTime(props.authorization.end_time) || '16:00';

const startDatetime = ref(`${initDate}T${initStartTime}`);
const endDatetime = ref(`${initDate}T${initEndTime}`);
const startDate = ref(initDate);
const endDate = ref(initDate);

const form = useForm({
    employee_id: props.authorization.employee_id,
    type: props.authorization.type,
    compensation_type_id: props.authorization.compensation_type_id || null,
    date: initDate,
    start_time: initStartTime,
    end_time: initEndTime,
    hours: props.authorization.hours || '',
    reason: props.authorization.reason,
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

const submit = () => {
    form.put(route('authorizations.update', props.authorization.id));
};

/** Active compensation type IDs for the selected employee. */
const selectedEmployeeData = computed(() => {
    if (!form.employee_id) return null;
    return props.employees.find(e => e.id == form.employee_id);
});

/** Group types for optgroup display, filtered by employee's active compensation types. */
const compensationTypes = computed(() => {
    const all = props.types.filter(t => t.group === 'compensation');
    const ids = selectedEmployeeData.value?.active_compensation_type_ids;
    if (!ids) return all;
    return all.filter(t => ids.includes(t.compensation_type_id));
});
const optionValue = (type) => {
    return `comp_${type.compensation_type_id}`;
};

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

/** Reset type selection when employee changes and selected type is no longer available. */
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

const isPending = props.authorization.status === 'pending';
</script>

<template>
    <Head title="Editar Autorizacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Autorizacion
            </h2>
        </template>

        <div class="max-w-3xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
                </Link>
            </div>

            <!-- Warning if not pending -->
            <div v-if="!isPending" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                <div class="flex">
                    <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            Esta autorizacion ya fue procesada y no puede ser editada.
                        </p>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Employee & Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Empleado *
                            </label>
                            <SearchableSelect
                                v-model="form.employee_id"
                                :options="employees"
                                value-key="id"
                                label-key="full_name"
                                secondary-key="employee_number"
                                placeholder="Buscar empleado..."
                                :disabled="!isPending"
                                :has-error="!!form.errors.employee_id"
                            />
                            <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.employee_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion *
                            </label>
                            <select
                                :value="selectedOptionValue"
                                @change="onTypeChange"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                                :class="{ 'border-red-500': form.errors.type }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="type in compensationTypes" :key="type.compensation_type_id" :value="optionValue(type)">
                                    {{ type.label }}
                                </option>
                            </select>
                            <p v-if="form.type && typeDescriptions[form.type]" class="mt-1 text-sm text-gray-500">
                                {{ typeDescriptions[form.type] }}
                            </p>
                            <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.type }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Date & Time - adapts to application_mode -->
                <div v-if="selectedApplicationMode" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fecha y Horario</h3>

                    <!-- per_hour -->
                    <div v-if="selectedApplicationMode === 'per_hour'" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha/Hora Inicio *</label>
                            <input v-model="startDatetime" type="datetime-local" :disabled="!isPending" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.date || form.errors.start_time }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                            <p v-if="form.errors.start_time" class="mt-1 text-sm text-red-600">{{ form.errors.start_time }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha/Hora Fin *</label>
                            <input v-model="endDatetime" type="datetime-local" :disabled="!isPending" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.end_time }" />
                            <p v-if="form.errors.end_time" class="mt-1 text-sm text-red-600">{{ form.errors.end_time }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Horas Totales</label>
                            <input v-model="form.hours" type="number" step="0.5" min="0" max="48" :disabled="!isPending" placeholder="Auto" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.hours }" />
                            <p v-if="form.errors.hours" class="mt-1 text-sm text-red-600">{{ form.errors.hours }}</p>
                            <p class="mt-1 text-xs text-gray-500">Se calcula automaticamente
                            </p>
                        </div>
                    </div>

                    <!-- per_day -->
                    <div v-else-if="selectedApplicationMode === 'per_day'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio *</label>
                            <input v-model="startDate" type="date" :disabled="!isPending" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.date }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin *</label>
                            <input v-model="endDate" type="date" :disabled="!isPending" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" />
                            <p v-if="form.hours" class="mt-1 text-sm text-gray-500">{{ form.hours }} dia(s)</p>
                        </div>
                    </div>

                    <!-- one_time: quantity only -->
                    <div v-else-if="selectedApplicationMode === 'one_time'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha *</label>
                            <input v-model="startDate" type="date" :disabled="!isPending" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.date }" />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">{{ form.errors.date }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad</label>
                            <input v-model="form.hours" type="number" step="1" min="1" :disabled="!isPending" placeholder="1" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100" :class="{ 'border-red-500': form.errors.hours }" />
                            <p v-if="form.errors.hours" class="mt-1 text-sm text-red-600">{{ form.errors.hours }}</p>
                            <p class="mt-1 text-xs text-gray-500">Numero de unidades (el monto se calcula del valor asignado al empleado)</p>
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
                            rows="4"
                            :disabled="!isPending"
                            placeholder="Describa el motivo de esta autorizacion..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Status Info -->
                <div v-if="!isPending" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Estado</h3>
                    <div class="flex items-center space-x-2">
                        <span
                            :class="[
                                authorization.status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800',
                                'px-3 py-1 rounded-full text-sm font-medium'
                            ]"
                        >
                            {{ authorization.status === 'approved' ? 'Aprobada' : 'Rechazada' }}
                        </span>
                        <span v-if="authorization.rejection_reason" class="text-sm text-gray-600">
                            - {{ authorization.rejection_reason }}
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('authorizations.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        {{ isPending ? 'Cancelar' : 'Volver' }}
                    </Link>
                    <button
                        v-if="isPending"
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Cambios' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
