<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    anomaly: Object,
    relatedAnomalies: Array,
    relatedAuthorizations: Array,
    can: Object,
});

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);

const severityColors = {
    critical: 'bg-red-100 text-red-800 border-red-300',
    warning: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    info: 'bg-blue-100 text-blue-800 border-blue-300',
};

const severityLabels = {
    critical: 'Critica',
    warning: 'Advertencia',
    info: 'Informativa',
};

const statusColors = {
    open: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    resolved: 'bg-green-100 text-green-800 border-green-300',
    dismissed: 'bg-gray-100 text-gray-800 border-gray-300',
    linked: 'bg-blue-100 text-blue-800 border-blue-300',
};

const statusLabels = {
    open: 'Abierta',
    resolved: 'Resuelta',
    dismissed: 'Descartada',
    linked: 'Vinculada',
};

const authStatusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

const authStatusColors = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-MX');
};

const formatDateTime = (datetime) => {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString('es-MX');
};

const formatTime = (time) => {
    if (!time) return '-';
    return time;
};

/* ----- Resolve action ----- */
const resolveForm = useForm({
    resolution_notes: '',
    two_factor_code: '',
});

const showResolveForm = ref(false);

const submitResolve = () => {
    resolveForm.post(route('anomalies.resolve', props.anomaly.id), {
        onSuccess: () => {
            showResolveForm.value = false;
        },
    });
};

/* ----- Dismiss action ----- */
const dismissForm = useForm({
    resolution_notes: '',
    two_factor_code: '',
});

const showDismissForm = ref(false);

const submitDismiss = () => {
    dismissForm.post(route('anomalies.dismiss', props.anomaly.id), {
        onSuccess: () => {
            showDismissForm.value = false;
        },
    });
};

/* ----- Link to authorization ----- */
const selectedAuthorizationId = ref('');

const linkToAuthorization = () => {
    if (!selectedAuthorizationId.value) return;
    router.post(route('anomalies.linkAuthorization', props.anomaly.id), {
        authorization_id: selectedAuthorizationId.value,
    });
};
</script>

<template>
    <Head :title="`Anomalia #${anomaly.id}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Detalle de Anomalia
                </h2>
                <span :class="[statusColors[anomaly.status], 'px-3 py-1 text-sm rounded-full border']">
                    {{ statusLabels[anomaly.status] }}
                </span>
            </div>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6">
            <Link :href="route('anomalies.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a anomalias
            </Link>
        </div>

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Main Content (2/3) -->
            <div class="lg:w-2/3 space-y-6">
                <!-- Main Card -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <span :class="[severityColors[anomaly.severity], 'px-3 py-1 text-sm font-medium rounded-full border']">
                                {{ severityLabels[anomaly.severity] }}
                            </span>
                            <h3 class="text-xl font-semibold text-gray-800 mt-3">{{ anomaly.type_name }}</h3>
                        </div>
                        <span v-if="anomaly.auto_detected" class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                            Auto-detectada
                        </span>
                    </div>

                    <p class="text-gray-700 mb-6 whitespace-pre-wrap">{{ anomaly.description }}</p>

                    <!-- Expected vs Actual -->
                    <div v-if="anomaly.expected_value || anomaly.actual_value" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                        <div class="bg-green-50 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-green-700 mb-1">Valor Esperado</h4>
                            <p class="text-lg font-semibold text-green-900">{{ anomaly.expected_value || '-' }}</p>
                        </div>
                        <div class="bg-red-50 rounded-lg p-4">
                            <h4 class="text-sm font-medium text-red-700 mb-1">Valor Real</h4>
                            <p class="text-lg font-semibold text-red-900">{{ anomaly.actual_value || '-' }}</p>
                        </div>
                    </div>

                    <!-- Deviation -->
                    <div v-if="anomaly.deviation_minutes" class="bg-orange-50 rounded-lg p-4 mb-6">
                        <h4 class="text-sm font-medium text-orange-700 mb-1">Desviacion</h4>
                        <p class="text-lg font-semibold text-orange-900">{{ anomaly.deviation_minutes }} minutos</p>
                    </div>

                    <!-- Details Grid -->
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Fecha</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ formatDate(anomaly.work_date) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Estado</dt>
                            <dd class="mt-1">
                                <span :class="[statusColors[anomaly.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                    {{ statusLabels[anomaly.status] }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Empleado</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.employee?.full_name }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Departamento</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.employee?.department?.name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Posicion</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.employee?.position?.name || '-' }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Attendance Record Card -->
                <div v-if="anomaly.attendance_record" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Registro de Asistencia</h3>
                    <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Entrada</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ formatTime(anomaly.attendance_record.check_in) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Salida</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ formatTime(anomaly.attendance_record.check_out) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Horas Trabajadas</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.worked_hours ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Horas Extra</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.overtime_hours ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Horas Velada</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.night_shift_hours ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Minutos Tarde</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.late_minutes ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Salida Temprana</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.early_departure_minutes ?? '-' }} min</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Estado</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.status || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Registros Crudos</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.raw_punches_count ?? '-' }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Resolution Card -->
                <div v-if="anomaly.status !== 'open'" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Resolucion</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Resuelto por</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.resolved_by_user?.name || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Fecha de resolucion</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ formatDateTime(anomaly.resolved_at) }}</dd>
                        </div>
                        <div v-if="anomaly.resolution_notes">
                            <dt class="text-sm font-medium text-gray-500">Notas de resolucion</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ anomaly.resolution_notes }}</dd>
                        </div>
                        <div v-if="anomaly.linked_authorization" class="mt-4 p-4 bg-blue-50 rounded-lg">
                            <h4 class="text-sm font-medium text-blue-700 mb-2">Autorizacion Vinculada</h4>
                            <dl class="space-y-2">
                                <div>
                                    <dt class="text-xs text-blue-600">Tipo</dt>
                                    <dd class="text-sm text-blue-900">{{ anomaly.linked_authorization.type }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-blue-600">Fecha</dt>
                                    <dd class="text-sm text-blue-900">{{ formatDate(anomaly.linked_authorization.date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-blue-600">Estado</dt>
                                    <dd class="text-sm text-blue-900">{{ authStatusLabels[anomaly.linked_authorization.status] || anomaly.linked_authorization.status }}</dd>
                                </div>
                                <div class="mt-2">
                                    <Link
                                        :href="route('authorizations.show', anomaly.linked_authorization.id)"
                                        class="text-pink-600 hover:text-pink-800 text-sm"
                                    >
                                        Ver autorizacion
                                    </Link>
                                </div>
                            </dl>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Sidebar (1/3) -->
            <div class="lg:w-1/3 space-y-6">
                <!-- Action Card -->
                <div v-if="anomaly.status === 'open'" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Acciones</h3>

                    <!-- Resolve -->
                    <div v-if="can.resolve" class="mb-6">
                        <button
                            v-if="!showResolveForm"
                            @click="showResolveForm = true; showDismissForm = false;"
                            class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        >
                            Resolver
                        </button>
                        <div v-if="showResolveForm">
                            <form @submit.prevent="submitResolve">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notas de resolucion</label>
                                <textarea
                                    v-model="resolveForm.resolution_notes"
                                    rows="3"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 mb-2"
                                    placeholder="Describa como se resolvio..."
                                ></textarea>
                                <p v-if="resolveForm.errors.resolution_notes" class="mb-2 text-sm text-red-600">
                                    {{ resolveForm.errors.resolution_notes }}
                                </p>
                                <div v-if="hasTwoFactor" class="mb-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Codigo de verificacion</label>
                                    <input
                                        v-model="resolveForm.two_factor_code"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        class="w-full text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                        placeholder="000000"
                                    />
                                    <p v-if="resolveForm.errors.two_factor_code" class="mt-1 text-sm text-red-600">
                                        {{ resolveForm.errors.two_factor_code }}
                                    </p>
                                </div>
                                <div class="flex space-x-2">
                                    <button
                                        type="submit"
                                        :disabled="resolveForm.processing"
                                        class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                                    >
                                        Confirmar
                                    </button>
                                    <button
                                        type="button"
                                        @click="showResolveForm = false"
                                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Dismiss -->
                    <div v-if="can.dismiss" class="mb-6">
                        <button
                            v-if="!showDismissForm"
                            @click="showDismissForm = true; showResolveForm = false;"
                            class="w-full px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700"
                        >
                            Descartar
                        </button>
                        <div v-if="showDismissForm">
                            <form @submit.prevent="submitDismiss">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Motivo del descarte</label>
                                <textarea
                                    v-model="dismissForm.resolution_notes"
                                    rows="3"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 mb-2"
                                    placeholder="Indique por que se descarta..."
                                ></textarea>
                                <p v-if="dismissForm.errors.resolution_notes" class="mb-2 text-sm text-red-600">
                                    {{ dismissForm.errors.resolution_notes }}
                                </p>
                                <div v-if="hasTwoFactor" class="mb-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Codigo de verificacion</label>
                                    <input
                                        v-model="dismissForm.two_factor_code"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        maxlength="6"
                                        class="w-full text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                        placeholder="000000"
                                    />
                                    <p v-if="dismissForm.errors.two_factor_code" class="mt-1 text-sm text-red-600">
                                        {{ dismissForm.errors.two_factor_code }}
                                    </p>
                                </div>
                                <div class="flex space-x-2">
                                    <button
                                        type="submit"
                                        :disabled="dismissForm.processing"
                                        class="flex-1 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50"
                                    >
                                        Confirmar
                                    </button>
                                    <button
                                        type="button"
                                        @click="showDismissForm = false"
                                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                                    >
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Link to Authorization -->
                    <div v-if="can.resolve && relatedAuthorizations.length > 0">
                        <h4 class="text-sm font-medium text-gray-700 mb-2">Vincular a Autorizacion</h4>
                        <select
                            v-model="selectedAuthorizationId"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 mb-2"
                        >
                            <option value="">Seleccionar autorizacion...</option>
                            <option v-for="auth in relatedAuthorizations" :key="auth.id" :value="auth.id">
                                #{{ auth.id }} - {{ auth.type }} ({{ formatDate(auth.date) }})
                            </option>
                        </select>
                        <button
                            @click="linkToAuthorization"
                            :disabled="!selectedAuthorizationId"
                            class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                        >
                            Vincular
                        </button>
                    </div>
                </div>

                <!-- Related Anomalies Card -->
                <div v-if="relatedAnomalies.length > 0" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Anomalias Relacionadas</h3>
                    <div class="space-y-3">
                        <div
                            v-for="related in relatedAnomalies"
                            :key="related.id"
                            class="p-3 bg-gray-50 rounded-lg"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-900">{{ related.type_name }}</span>
                                <span :class="[severityColors[related.severity], 'px-2 py-0.5 text-xs font-medium rounded-full']">
                                    {{ severityLabels[related.severity] }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span :class="[statusColors[related.status], 'px-2 py-0.5 text-xs font-medium rounded-full']">
                                    {{ statusLabels[related.status] }}
                                </span>
                                <Link
                                    :href="route('anomalies.show', related.id)"
                                    class="text-pink-600 hover:text-pink-800 text-xs"
                                >
                                    Ver detalle
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Related Authorizations Card -->
                <div v-if="relatedAuthorizations.length > 0" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Autorizaciones Relacionadas</h3>
                    <div class="space-y-3">
                        <div
                            v-for="auth in relatedAuthorizations"
                            :key="auth.id"
                            class="p-3 bg-gray-50 rounded-lg"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-900">{{ auth.type }}</span>
                                <span :class="[authStatusColors[auth.status], 'px-2 py-0.5 text-xs font-medium rounded-full']">
                                    {{ authStatusLabels[auth.status] || auth.status }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span>{{ auth.hours ? auth.hours + 'h' : '-' }}</span>
                                <Link
                                    :href="route('authorizations.show', auth.id)"
                                    class="text-pink-600 hover:text-pink-800"
                                >
                                    Ver
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
