<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    incident: Object,
    incidentTypes: Array,
    employees: Array,
});

const form = useForm({
    employee_id: props.incident.employee_id,
    incident_type_id: props.incident.incident_type_id,
    start_date: props.incident.start_date,
    end_date: props.incident.end_date,
    reason: props.incident.reason || '',
});

const selectedIncidentType = computed(() => {
    if (!form.incident_type_id) return null;
    return props.incidentTypes.find(t => t.id == form.incident_type_id);
});

const daysCount = computed(() => {
    if (!form.start_date || !form.end_date) return 0;
    const start = new Date(form.start_date);
    const end = new Date(form.end_date);
    const diff = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
    return diff > 0 ? diff : 0;
});

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
};

const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobada',
    rejected: 'Rechazada',
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const submit = () => {
    form.put(route('incidents.update', props.incident.id));
};
</script>

<template>
    <Head title="Editar Incidencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Incidencia
            </h2>
        </template>

        <div class="max-w-3xl">
            <div class="mb-6">
                <Link
                    :href="route('incidents.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a incidencias
                </Link>
            </div>

            <!-- Current Status -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-full bg-pink-100 flex items-center justify-center">
                            <span class="text-xl text-pink-600 font-bold">
                                {{ incident.employee?.full_name?.charAt(0) || '?' }}
                            </span>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-gray-800">
                                {{ incident.employee?.full_name }}
                            </h3>
                            <p class="text-gray-500">{{ incident.employee?.employee_number }}</p>
                        </div>
                    </div>
                    <span :class="[statusColors[incident.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                        {{ statusLabels[incident.status] }}
                    </span>
                </div>

                <!-- Status Info for Approved/Rejected -->
                <div v-if="incident.status !== 'pending'" class="mt-4 pt-4 border-t border-gray-200">
                    <div v-if="incident.status === 'approved'" class="text-sm text-gray-600">
                        <span class="font-medium">Aprobada por:</span>
                        {{ incident.approved_by_user?.name || 'N/A' }}
                        <span class="mx-2">|</span>
                        <span class="font-medium">Fecha:</span>
                        {{ incident.approved_at ? formatDate(incident.approved_at) : 'N/A' }}
                    </div>
                    <div v-if="incident.status === 'rejected'" class="text-sm">
                        <p class="text-gray-600">
                            <span class="font-medium">Rechazada por:</span>
                            {{ incident.approved_by_user?.name || 'N/A' }}
                        </p>
                        <p v-if="incident.rejection_reason" class="mt-2 text-red-600">
                            <span class="font-medium">Motivo:</span>
                            {{ incident.rejection_reason }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Edit Form (only if pending) -->
            <template v-if="incident.status === 'pending'">
                <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">
                    <!-- Employee Selection -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Empleado <span class="text-red-500">*</span>
                        </label>
                        <select
                            v-model="form.employee_id"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.employee_id }"
                        >
                            <option value="">Seleccionar empleado...</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.employee_number }} - {{ emp.full_name }}
                            </option>
                        </select>
                        <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.employee_id }}
                        </p>
                    </div>

                    <!-- Incident Type -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Tipo de Incidencia <span class="text-red-500">*</span>
                        </label>
                        <select
                            v-model="form.incident_type_id"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.incident_type_id }"
                        >
                            <option value="">Seleccionar tipo...</option>
                            <option v-for="type in incidentTypes" :key="type.id" :value="type.id">
                                {{ type.name }}
                            </option>
                        </select>
                        <p v-if="form.errors.incident_type_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.incident_type_id }}
                        </p>
                    </div>

                    <!-- Incident Type Info -->
                    <div v-if="selectedIncidentType" class="flex flex-wrap gap-2">
                        <span
                            class="px-2 py-1 text-xs font-medium rounded-full"
                            :class="selectedIncidentType.is_paid ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                        >
                            {{ selectedIncidentType.is_paid ? 'Con goce de sueldo' : 'Sin goce de sueldo' }}
                        </span>
                        <span
                            v-if="selectedIncidentType.deducts_vacation"
                            class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800"
                        >
                            Descuenta vacaciones
                        </span>
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha Inicio <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.start_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.start_date }"
                            />
                            <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.start_date }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha Fin <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.end_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.end_date }"
                            />
                            <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.end_date }}
                            </p>
                        </div>
                    </div>

                    <!-- Days Count -->
                    <div v-if="daysCount > 0" class="flex items-center">
                        <span class="text-sm text-gray-600">Total de dias:</span>
                        <span class="ml-2 px-3 py-1 bg-pink-100 text-pink-800 font-medium rounded-full">
                            {{ daysCount }} {{ daysCount === 1 ? 'dia' : 'dias' }}
                        </span>
                    </div>

                    <!-- Reason -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Motivo / Observaciones
                        </label>
                        <textarea
                            v-model="form.reason"
                            rows="3"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                            placeholder="Descripcion o motivo de la incidencia..."
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-4 pt-4 border-t">
                        <Link
                            :href="route('incidents.index')"
                            class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                        >
                            Cancelar
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Guardando...' : 'Guardar Cambios' }}
                        </button>
                    </div>
                </form>
            </template>

            <!-- Read-only view for non-pending incidents -->
            <template v-else>
                <div class="bg-white rounded-lg shadow p-6 space-y-6">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                        <p class="text-sm text-yellow-700">
                            Esta incidencia ya fue {{ incident.status === 'approved' ? 'aprobada' : 'rechazada' }} y no puede ser editada.
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Incidencia</label>
                            <p class="text-gray-900">{{ incident.incident_type?.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Total de Dias</label>
                            <p class="text-gray-900">{{ incident.days_count }} dias</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Inicio</label>
                            <p class="text-gray-900">{{ formatDate(incident.start_date) }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin</label>
                            <p class="text-gray-900">{{ formatDate(incident.end_date) }}</p>
                        </div>
                    </div>

                    <div v-if="incident.reason">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo</label>
                        <p class="text-gray-900">{{ incident.reason }}</p>
                    </div>

                    <div class="pt-4 border-t">
                        <Link
                            :href="route('incidents.index')"
                            class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                        >
                            Volver a Incidencias
                        </Link>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
