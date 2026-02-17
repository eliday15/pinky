<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TwoFactorModal from '@/Components/TwoFactorModal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    authorization: Object,
    can: Object,
});

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);
const showApproveModal = ref(false);

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    approved: 'bg-green-100 text-green-800 border-green-300',
    rejected: 'bg-red-100 text-red-800 border-red-300',
};

const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

const typeLabels = {
    overtime: 'Horas Extra',
    night_shift: 'Velada',
    exit_permission: 'Permiso de Salida',
    entry_permission: 'Permiso de Entrada',
    schedule_change: 'Cambio de Horario',
    special: 'Especial',
};

const rejectForm = useForm({
    rejection_reason: '',
    two_factor_code: '',
});

const showRejectModal = ref(false);

const handleApprove = () => {
    if (hasTwoFactor.value) {
        showApproveModal.value = true;
    } else {
        router.post(route('authorizations.approve', props.authorization.id));
    }
};

const submitReject = () => {
    rejectForm.post(route('authorizations.reject', props.authorization.id), {
        onSuccess: () => {
            showRejectModal.value = false;
            rejectForm.reset();
        },
    });
};
</script>

<template>
    <Head :title="`Autorizacion #${authorization.id}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Autorizacion #{{ authorization.id }}
                </h2>
                <span :class="[statusColors[authorization.status], 'px-3 py-1 text-sm rounded-full border']">
                    {{ statusLabels[authorization.status] }}
                </span>
            </div>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
                </Link>
            </div>

            <div class="space-y-6">
                <!-- Main Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion de la Autorizacion</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Tipo</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ typeLabels[authorization.type] || authorization.type }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Fecha</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ new Date(authorization.date).toLocaleDateString('es-MX', {
                                            weekday: 'long',
                                            year: 'numeric',
                                            month: 'long',
                                            day: 'numeric'
                                        }) }}
                                    </dd>
                                </div>
                                <div v-if="authorization.start_time || authorization.end_time">
                                    <dt class="text-sm font-medium text-gray-500">Horario</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ authorization.start_time || '-' }} - {{ authorization.end_time || '-' }}
                                    </dd>
                                </div>
                                <div v-if="authorization.hours">
                                    <dt class="text-sm font-medium text-gray-500">Horas</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ authorization.hours }} horas
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Pre-autorizacion</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ authorization.is_pre_authorization ? 'Si' : 'No (Post-autorizacion)' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Empleado</h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Nombre</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ authorization.employee?.full_name }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Departamento</dt>
                                    <dd class="mt-1 text-sm text-gray-900">
                                        {{ authorization.employee?.department?.name || '-' }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Justificacion</h3>
                    <p class="text-gray-700 whitespace-pre-wrap">{{ authorization.reason }}</p>
                </div>

                <!-- Approval Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tramite</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Solicitado por</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ authorization.requested_by_user?.name || '-' }}
                                <span class="text-gray-500 ml-2">
                                    {{ new Date(authorization.created_at).toLocaleString('es-MX') }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="authorization.approved_by">
                            <dt class="text-sm font-medium text-gray-500">
                                {{ authorization.status === 'approved' ? 'Aprobado por' : 'Rechazado por' }}
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ authorization.approved_by_user?.name || '-' }}
                                <span v-if="authorization.approved_at" class="text-gray-500 ml-2">
                                    {{ new Date(authorization.approved_at).toLocaleString('es-MX') }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="authorization.rejection_reason">
                            <dt class="text-sm font-medium text-gray-500">Razon del rechazo</dt>
                            <dd class="mt-1 text-sm text-red-600">
                                {{ authorization.rejection_reason }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Actions -->
                <div class="flex justify-between">
                    <div>
                        <Link
                            v-if="can.edit"
                            :href="route('authorizations.edit', authorization.id)"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 mr-2"
                        >
                            Editar
                        </Link>
                        <Link
                            v-if="can.delete"
                            :href="route('authorizations.destroy', authorization.id)"
                            method="delete"
                            as="button"
                            class="px-4 py-2 border border-red-300 text-red-700 rounded-lg hover:bg-red-50"
                            onclick="return confirm('Esta seguro de eliminar esta autorizacion?')"
                        >
                            Eliminar
                        </Link>
                    </div>
                    <div v-if="authorization.status === 'pending'" class="space-x-2">
                        <button
                            v-if="can.approve"
                            @click="handleApprove"
                            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        >
                            Aprobar
                        </button>
                        <button
                            v-if="can.reject"
                            @click="showRejectModal = true"
                            class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                        >
                            Rechazar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve 2FA Modal -->
        <TwoFactorModal
            :show="showApproveModal"
            :action="route('authorizations.approve', authorization.id)"
            method="post"
            title="Aprobar Autorizacion"
            message="Ingresa tu codigo de verificacion para aprobar esta autorizacion."
            @close="showApproveModal = false"
        />

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showRejectModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Rechazar Autorizacion
                    </h3>
                    <form @submit.prevent="submitReject">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Razon del rechazo *
                            </label>
                            <textarea
                                v-model="rejectForm.rejection_reason"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                required
                            ></textarea>
                            <p v-if="rejectForm.errors.rejection_reason" class="mt-1 text-sm text-red-600">
                                {{ rejectForm.errors.rejection_reason }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor" class="mb-4">
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
                        <div class="flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="showRejectModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="rejectForm.processing"
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                            >
                                Rechazar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
