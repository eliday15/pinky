<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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

const form = useForm({
    employee_id: props.authorization.employee_id,
    type: props.authorization.type,
    date: formatDate(props.authorization.date),
    start_time: formatTime(props.authorization.start_time),
    end_time: formatTime(props.authorization.end_time),
    hours: props.authorization.hours || '',
    reason: props.authorization.reason,
});

const submit = () => {
    form.put(route('authorizations.update', props.authorization.id));
};

const typeDescriptions = {
    overtime: 'Horas adicionales trabajadas fuera del horario normal',
    night_shift: 'Turno nocturno o velada completa',
    exit_permission: 'Permiso para salir antes del horario establecido',
    entry_permission: 'Permiso para entrar despues del horario establecido',
    schedule_change: 'Cambio temporal en el horario de trabajo',
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
                <!-- Employee & Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Empleado *
                            </label>
                            <select
                                v-model="form.employee_id"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                                :class="{ 'border-red-500': form.errors.employee_id }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                    {{ emp.full_name }} ({{ emp.employee_number }})
                                </option>
                            </select>
                            <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.employee_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion *
                            </label>
                            <select
                                v-model="form.type"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                                :class="{ 'border-red-500': form.errors.type }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="type in types" :key="type.value" :value="type.value">
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

                <!-- Date & Time -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fecha y Horario</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha *
                            </label>
                            <input
                                v-model="form.date"
                                type="date"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                                :class="{ 'border-red-500': form.errors.date }"
                            />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.date }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Inicio
                            </label>
                            <input
                                v-model="form.start_time"
                                type="time"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Fin
                            </label>
                            <input
                                v-model="form.end_time"
                                type="time"
                                :disabled="!isPending"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Horas Totales
                            </label>
                            <input
                                v-model="form.hours"
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                :disabled="!isPending"
                                placeholder="Auto o manual"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Se calcula automaticamente si pone inicio/fin
                            </p>
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
