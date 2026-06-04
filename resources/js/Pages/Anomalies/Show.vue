<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ResolveAnomalyModal from '@/Components/Anomalies/ResolveAnomalyModal.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatDate as fmtDate, formatDateTime as fmtDateTime } from '@/utils/date';
import {
    severityBorderColors as severityColors,
    severityLabels,
    statusBorderColors as statusColors,
    statusLabels,
    resolutionMethodLabels,
    resolutionMethodColors,
} from '@/utils/anomalyConstants';

const props = defineProps({
    anomaly: Object,
    relatedAnomalies: Array,
    relatedAuthorizations: Array,
    linkableAuthorizations: { type: Array, default: () => [] },
    linkableIncidents: { type: Array, default: () => [] },
    can: Object,
});

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

const formatDate = (date) => fmtDate(date);

const formatDateTime = (datetime) => fmtDateTime(datetime);

const formatTime = (time) => {
    if (!time) return '-';
    return time;
};

/* ----- Guided resolution modal ----- */
const showResolveModal = ref(false);
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
                            <dt class="text-sm font-medium text-gray-500">Turno Nocturno</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ anomaly.attendance_record.is_night_shift ? 'Sí' : 'No' }}</dd>
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
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Resolucion</h3>
                        <span
                            v-if="anomaly.resolution_method"
                            :class="[resolutionMethodColors[anomaly.resolution_method] || 'bg-gray-100 text-gray-700', 'px-2 py-1 text-xs font-medium rounded-full']"
                        >
                            {{ resolutionMethodLabels[anomaly.resolution_method] || anomaly.resolution_method }}
                        </span>
                    </div>
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
                        <div v-if="anomaly.linked_incident" class="mt-4 p-4 bg-teal-50 rounded-lg">
                            <h4 class="text-sm font-medium text-teal-700 mb-2">Permiso / Incidencia Vinculada</h4>
                            <dl class="space-y-2">
                                <div>
                                    <dt class="text-xs text-teal-600">Tipo</dt>
                                    <dd class="text-sm text-teal-900">{{ anomaly.linked_incident.incident_type?.name || 'Incidencia' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-teal-600">Periodo</dt>
                                    <dd class="text-sm text-teal-900">
                                        {{ formatDate(anomaly.linked_incident.start_date) }} — {{ formatDate(anomaly.linked_incident.end_date) }}
                                    </dd>
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

                    <button
                        v-if="can.resolve || can.dismiss"
                        @click="showResolveModal = true"
                        class="w-full px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                    >
                        Resolver anomalía
                    </button>
                    <p v-if="can.resolve || can.dismiss" class="mt-2 text-xs text-gray-500">
                        Se abrirá el formulario guiado con las opciones que aplican a este tipo de anomalía.
                    </p>
                    <p v-else class="text-sm text-gray-500">
                        No tienes permisos para resolver esta anomalía.
                    </p>
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

        <!-- Guided resolution modal (linkable lists come as page props here) -->
        <ResolveAnomalyModal
            :show="showResolveModal"
            :anomaly="anomaly"
            :linkable-authorizations="linkableAuthorizations"
            :linkable-incidents="linkableIncidents"
            :can="can"
            @close="showResolveModal = false"
            @resolved="showResolveModal = false"
        />
    </AppLayout>
</template>
