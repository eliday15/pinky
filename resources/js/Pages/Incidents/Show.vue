<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TwoFactorModal from '@/Components/TwoFactorModal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    incident: Object,
    can: Object,
});

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);
const showApproveModal = ref(false);
const showRejectModal = ref(false);
const rejectForm = useForm({
    rejection_reason: '',
    two_factor_code: '',
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
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
};

const formatDateTime = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const approveIncident = () => {
    if (hasTwoFactor.value) {
        showApproveModal.value = true;
    } else if (confirm('¿Aprobar esta incidencia?')) {
        router.post(route('incidents.approve', props.incident.id));
    }
};

const submitReject = () => {
    rejectForm.post(route('incidents.reject', props.incident.id), {
        preserveScroll: true,
        onSuccess: () => {
            showRejectModal.value = false;
            rejectForm.reset();
        },
    });
};

const deleteIncident = () => {
    if (confirm('¿Eliminar esta incidencia?')) {
        router.delete(route('incidents.destroy', props.incident.id));
    }
};
</script>

<template>
    <Head :title="`Incidencia - ${incident.employee?.full_name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Incidencia
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('incidents.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a incidencias
                </Link>
            </div>

            <!-- Status Banner -->
            <div
                v-if="incident.status === 'pending'"
                class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6"
            >
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <svg class="w-5 h-5 text-yellow-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-yellow-800 font-medium">Esta incidencia esta pendiente de aprobacion</span>
                    </div>
                    <div v-if="can?.approve" class="flex gap-2">
                        <button
                            @click="approveIncident"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm"
                        >
                            Aprobar
                        </button>
                        <button
                            @click="showRejectModal = true"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm"
                        >
                            Rechazar
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="incident.status === 'approved'"
                class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6"
            >
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <span class="text-green-800 font-medium">Incidencia aprobada</span>
                        <span v-if="incident.approved_by" class="text-green-600 text-sm ml-2">
                            por {{ incident.approved_by.name }} el {{ formatDateTime(incident.approved_at) }}
                        </span>
                    </div>
                </div>
            </div>

            <div
                v-if="incident.status === 'rejected'"
                class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6"
            >
                <div class="flex items-start">
                    <svg class="w-5 h-5 text-red-500 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <span class="text-red-800 font-medium">Incidencia rechazada</span>
                        <span v-if="incident.approved_by" class="text-red-600 text-sm ml-2">
                            por {{ incident.approved_by.name }} el {{ formatDateTime(incident.approved_at) }}
                        </span>
                        <p v-if="incident.rejection_reason" class="text-red-700 text-sm mt-1">
                            <strong>Motivo:</strong> {{ incident.rejection_reason }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Incident Details -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div class="flex items-center">
                        <span
                            class="px-3 py-1 text-sm font-medium rounded-full mr-3"
                            :style="{ backgroundColor: incident.incident_type?.color + '20', color: incident.incident_type?.color }"
                        >
                            {{ incident.incident_type?.name }}
                        </span>
                        <span :class="[statusColors[incident.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                            {{ statusLabels[incident.status] }}
                        </span>
                    </div>
                    <div v-if="can?.edit || can?.delete" class="flex gap-2">
                        <Link
                            v-if="can?.edit && incident.status === 'pending'"
                            :href="route('incidents.edit', incident.id)"
                            class="px-4 py-2 text-pink-600 border border-pink-600 rounded-lg hover:bg-pink-50 text-sm"
                        >
                            Editar
                        </Link>
                        <button
                            v-if="can?.delete"
                            @click="deleteIncident"
                            class="px-4 py-2 text-red-600 border border-red-600 rounded-lg hover:bg-red-50 text-sm"
                        >
                            Eliminar
                        </button>
                    </div>
                </div>

                <!-- Employee Info -->
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex items-center">
                        <div class="w-12 h-12 rounded-full bg-pink-100 flex items-center justify-center">
                            <span class="text-pink-600 text-lg font-medium">
                                {{ incident.employee?.full_name?.charAt(0) || '?' }}
                            </span>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-lg font-semibold text-gray-900">{{ incident.employee?.full_name }}</h3>
                            <p class="text-sm text-gray-600">
                                {{ incident.employee?.department?.name }} &middot; {{ incident.employee?.employee_number }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Details -->
                <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-1">Fecha de Inicio</h4>
                        <p class="text-gray-900">{{ formatDate(incident.start_date) }}</p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-1">Fecha de Fin</h4>
                        <p class="text-gray-900">{{ formatDate(incident.end_date) }}</p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-1">Dias</h4>
                        <p class="text-gray-900 text-lg font-semibold">{{ incident.days_count }}</p>
                    </div>
                    <div>
                        <h4 class="text-sm font-medium text-gray-500 mb-1">Tipo de Incidencia</h4>
                        <p class="text-gray-900">{{ incident.incident_type?.name }}</p>
                        <p v-if="incident.incident_type?.deducts_vacation" class="text-xs text-orange-600 mt-1">
                            Se descuenta de vacaciones
                        </p>
                    </div>
                </div>

                <!-- Reason -->
                <div v-if="incident.reason" class="px-6 py-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Motivo / Observaciones</h4>
                    <p class="text-gray-900 whitespace-pre-wrap">{{ incident.reason }}</p>
                </div>

                <!-- Document -->
                <div v-if="incident.document_path" class="px-6 py-4 border-t border-gray-200">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Documento Adjunto</h4>
                    <a
                        :href="`/storage/${incident.document_path}`"
                        target="_blank"
                        class="inline-flex items-center text-pink-600 hover:text-pink-800"
                    >
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Ver documento
                    </a>
                </div>

                <!-- Timestamps -->
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 text-sm text-gray-500">
                    <p>Creada: {{ formatDateTime(incident.created_at) }}</p>
                    <p v-if="incident.updated_at !== incident.created_at">
                        Actualizada: {{ formatDateTime(incident.updated_at) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Approve 2FA Modal -->
        <TwoFactorModal
            :show="showApproveModal"
            :action="route('incidents.approve', incident.id)"
            method="post"
            title="Aprobar Incidencia"
            message="Ingresa tu codigo de verificacion para aprobar esta incidencia."
            @close="showApproveModal = false"
        />

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Rechazar Incidencia</h3>
                </div>
                <form @submit.prevent="submitReject">
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Motivo del rechazo *
                            </label>
                            <textarea
                                v-model="rejectForm.rejection_reason"
                                rows="4"
                                required
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="Indica el motivo por el cual se rechaza esta incidencia..."
                            ></textarea>
                            <p v-if="rejectForm.errors.rejection_reason" class="mt-1 text-sm text-red-600">
                                {{ rejectForm.errors.rejection_reason }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo de verificacion *
                            </label>
                            <input
                                v-model="rejectForm.two_factor_code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                class="w-full text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="000000"
                            />
                            <p v-if="rejectForm.errors.two_factor_code" class="mt-1 text-sm text-red-600">
                                {{ rejectForm.errors.two_factor_code }}
                            </p>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-2">
                        <button
                            type="button"
                            @click="showRejectModal = false"
                            class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="rejectForm.processing"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                        >
                            {{ rejectForm.processing ? 'Rechazando...' : 'Rechazar Incidencia' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
