<script setup>
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatDate } from '@/utils/date';
import {
    severityColors,
    severityLabels,
    typeIcons,
    fallbackTypeIcon,
} from '@/utils/anomalyConstants';

const props = defineProps({
    show: { type: Boolean, default: false },
    // Full row (Index) or show() anomaly — both serialize anomaly_type,
    // type_name, severity, work_date, expected/actual values and employee.
    anomaly: { type: Object, default: null },
    linkableAuthorizations: { type: Array, default: () => [] },
    linkableIncidents: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    // True while the parent lazy-loads the linkable lists (Index page).
    loadingLinkables: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'resolved']);

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);

/* ----- Action catalog ----- */
const ACTION = {
    CREATE_AUTH: 'create_authorization',
    LINK_AUTH: 'link_authorization',
    FIX_PUNCHES: 'fix_punches',
    LINK_INCIDENT: 'link_incident',
    JUSTIFY: 'justify',
    DISMISS: 'dismiss',
};

const ACTION_META = {
    [ACTION.CREATE_AUTH]: {
        label: 'Crear autorización retroactiva',
        desc: 'Genera una autorización con horarios sugeridos a partir de las checadas reales.',
        icon: 'M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        iconBg: 'bg-pink-100 text-pink-600',
    },
    [ACTION.LINK_AUTH]: {
        label: 'Vincular autorización existente',
        desc: 'Asocia una autorización aprobada del mismo empleado y fecha.',
        icon: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
        iconBg: 'bg-blue-100 text-blue-600',
    },
    [ACTION.FIX_PUNCHES]: {
        label: 'Corregir checadas',
        desc: 'Abre el registro de asistencia para editar entrada/salida; la anomalía se resuelve sola al corregirlo.',
        icon: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        iconBg: 'bg-indigo-100 text-indigo-600',
    },
    [ACTION.LINK_INCIDENT]: {
        label: 'Vincular permiso / incidencia',
        desc: 'Asocia un permiso o incidencia aprobada que cubre la fecha.',
        icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
        iconBg: 'bg-teal-100 text-teal-600',
    },
    [ACTION.JUSTIFY]: {
        label: 'Justificar y resolver',
        desc: 'Documenta la justificación y marca la anomalía como resuelta.',
        icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        iconBg: 'bg-green-100 text-green-600',
    },
    [ACTION.DISMISS]: {
        label: 'Descartar (falso positivo)',
        desc: 'Marca la anomalía como falso positivo con una nota obligatoria.',
        icon: 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
        iconBg: 'bg-gray-100 text-gray-600',
    },
};

// Actions that make sense per anomaly type (display order matters).
const TYPE_ACTIONS = {
    missing_checkout: [ACTION.FIX_PUNCHES, ACTION.JUSTIFY, ACTION.DISMISS],
    missing_checkin: [ACTION.FIX_PUNCHES, ACTION.JUSTIFY, ACTION.DISMISS],
    duplicate_punches: [ACTION.FIX_PUNCHES, ACTION.JUSTIFY, ACTION.DISMISS],
    schedule_deviation: [ACTION.FIX_PUNCHES, ACTION.JUSTIFY, ACTION.DISMISS],
    unauthorized_overtime: [ACTION.CREATE_AUTH, ACTION.LINK_AUTH, ACTION.JUSTIFY, ACTION.DISMISS],
    unauthorized_velada: [ACTION.CREATE_AUTH, ACTION.LINK_AUTH, ACTION.JUSTIFY, ACTION.DISMISS],
    excessive_overtime: [ACTION.CREATE_AUTH, ACTION.LINK_AUTH, ACTION.JUSTIFY, ACTION.DISMISS],
    velada_missing_confirmation: [ACTION.LINK_AUTH, ACTION.JUSTIFY, ACTION.DISMISS],
    late_arrival: [ACTION.LINK_INCIDENT, ACTION.JUSTIFY, ACTION.DISMISS],
    early_departure: [ACTION.LINK_INCIDENT, ACTION.JUSTIFY, ACTION.DISMISS],
    excessive_break: [ACTION.JUSTIFY, ACTION.DISMISS],
    missing_lunch: [ACTION.JUSTIFY, ACTION.DISMISS],
};
const DEFAULT_ACTIONS = [ACTION.JUSTIFY, ACTION.DISMISS];

const attendanceRecordId = computed(() =>
    props.anomaly?.attendance_record?.id ?? props.anomaly?.attendance_record_id ?? null,
);

const availableActions = computed(() => {
    if (!props.anomaly) return [];
    const keys = TYPE_ACTIONS[props.anomaly.anomaly_type] || DEFAULT_ACTIONS;

    return keys.filter((k) => {
        if (k === ACTION.JUSTIFY) return !!props.can.resolve;
        if (k === ACTION.DISMISS) return !!props.can.dismiss;
        if (k === ACTION.LINK_AUTH) return !!props.can.resolve && (props.loadingLinkables || props.linkableAuthorizations.length > 0);
        if (k === ACTION.LINK_INCIDENT) return !!props.can.resolve && (props.loadingLinkables || props.linkableIncidents.length > 0);
        if (k === ACTION.CREATE_AUTH) return !!props.can.createAuthorization;
        if (k === ACTION.FIX_PUNCHES) return !!props.can.editAttendance && !!attendanceRecordId.value;
        return true;
    });
});

/* ----- State ----- */
const chosenAction = ref(null);

/* ----- Forms (one per network action so error namespaces stay clean) ----- */
const resolveForm = useForm({ resolution_method: 'justified', resolution_notes: '', two_factor_code: '' });
const dismissForm = useForm({ resolution_notes: '', two_factor_code: '' });
const linkAuthForm = useForm({ authorization_id: '' });
const linkIncidentForm = useForm({ incident_id: '' });

const resetForms = () => {
    resolveForm.reset();
    resolveForm.clearErrors();
    dismissForm.reset();
    dismissForm.clearErrors();
    linkAuthForm.reset();
    linkAuthForm.clearErrors();
    linkIncidentForm.reset();
    linkIncidentForm.clearErrors();
};

watch(() => [props.show, props.anomaly?.id], () => {
    if (props.show) {
        resetForms();
        chosenAction.value = availableActions.value.length === 1 ? availableActions.value[0] : null;
    }
});

const activeForm = computed(() => ({
    [ACTION.JUSTIFY]: resolveForm,
    [ACTION.DISMISS]: dismissForm,
    [ACTION.LINK_AUTH]: linkAuthForm,
    [ACTION.LINK_INCIDENT]: linkIncidentForm,
}[chosenAction.value] ?? null));

const activeErrors = computed(() => activeForm.value?.errors ?? {});
const activeProcessing = computed(() => activeForm.value?.processing ?? false);

const needsTwoFactor = computed(() =>
    hasTwoFactor.value && [ACTION.JUSTIFY, ACTION.DISMISS].includes(chosenAction.value),
);

// Shared proxies so the notes/2FA inputs bind to whichever form is active.
const notesModel = computed({
    get: () => (chosenAction.value === ACTION.DISMISS ? dismissForm : resolveForm).resolution_notes,
    set: (v) => { (chosenAction.value === ACTION.DISMISS ? dismissForm : resolveForm).resolution_notes = v; },
});
const twoFactorModel = computed({
    get: () => (chosenAction.value === ACTION.DISMISS ? dismissForm : resolveForm).two_factor_code,
    set: (v) => { (chosenAction.value === ACTION.DISMISS ? dismissForm : resolveForm).two_factor_code = v; },
});

const notesTooShort = computed(() => (notesModel.value || '').trim().length < 5);

const submitDisabled = computed(() => {
    if (activeProcessing.value) return true;
    if (chosenAction.value === ACTION.JUSTIFY || chosenAction.value === ACTION.DISMISS) return notesTooShort.value;
    if (chosenAction.value === ACTION.LINK_AUTH) return !linkAuthForm.authorization_id;
    if (chosenAction.value === ACTION.LINK_INCIDENT) return !linkIncidentForm.incident_id;
    return false;
});

const done = () => {
    emit('resolved');
    emit('close');
};

const submit = () => {
    const id = props.anomaly.id;
    switch (chosenAction.value) {
        case ACTION.JUSTIFY:
            resolveForm.post(route('anomalies.resolve', id), { preserveScroll: true, onSuccess: done });
            break;
        case ACTION.DISMISS:
            dismissForm.post(route('anomalies.dismiss', id), { preserveScroll: true, onSuccess: done });
            break;
        case ACTION.LINK_AUTH:
            linkAuthForm.post(route('anomalies.linkAuthorization', id), { preserveScroll: true, onSuccess: done });
            break;
        case ACTION.LINK_INCIDENT:
            linkIncidentForm.post(route('anomalies.linkIncident', id), { preserveScroll: true, onSuccess: done });
            break;
    }
};

const goCreateAuthorization = () => router.visit(route('authorizations.create', { anomaly: props.anomaly.id }));
const goFixPunches = () => router.visit(route('attendance.edit', attendanceRecordId.value));

const recordCheckIn = computed(() => props.anomaly?.attendance_record?.check_in ?? null);
const recordCheckOut = computed(() => props.anomaly?.attendance_record?.check_out ?? null);
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="$emit('close')">
        <div v-if="anomaly" class="p-6">
            <!-- Context header -->
            <div class="flex items-start justify-between border-b border-gray-200 pb-4 mb-4">
                <div class="flex items-start gap-3">
                    <span class="p-2 rounded-full" :class="severityColors[anomaly.severity]">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                :d="typeIcons[anomaly.anomaly_type] || fallbackTypeIcon" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ anomaly.type_name }}</h3>
                        <p class="text-sm text-gray-500">
                            {{ anomaly.employee?.full_name }} · {{ formatDate(anomaly.work_date) }}
                        </p>
                    </div>
                </div>
                <span :class="[severityColors[anomaly.severity], 'px-2 py-1 text-xs font-medium rounded-full']">
                    {{ severityLabels[anomaly.severity] }}
                </span>
            </div>

            <!-- Expected vs actual + deviation -->
            <div v-if="anomaly.expected_value || anomaly.actual_value" class="grid grid-cols-2 gap-3 mb-4 text-sm">
                <div class="bg-green-50 rounded-md p-3">
                    <span class="text-xs text-green-700">Esperado</span>
                    <p class="font-semibold text-green-900">{{ anomaly.expected_value || '—' }}</p>
                </div>
                <div class="bg-red-50 rounded-md p-3">
                    <span class="text-xs text-red-700">Real</span>
                    <p class="font-semibold text-red-900">
                        {{ anomaly.actual_value || '—' }}
                        <span v-if="anomaly.deviation_minutes" class="ml-1 text-xs font-normal text-red-700">
                            ({{ anomaly.deviation_minutes }} min)
                        </span>
                    </p>
                </div>
            </div>

            <!-- Action chooser -->
            <fieldset class="space-y-2 mb-4">
                <legend class="text-sm font-medium text-gray-700 mb-2">¿Cómo deseas resolver esta anomalía?</legend>
                <p v-if="availableActions.length === 0" class="text-sm text-gray-500">
                    No tienes permisos para resolver esta anomalía.
                </p>
                <label
                    v-for="a in availableActions"
                    :key="a"
                    :class="[
                        'flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition',
                        chosenAction === a
                            ? 'border-pink-500 ring-1 ring-pink-500 bg-pink-50'
                            : 'border-gray-200 hover:border-gray-300',
                    ]"
                >
                    <input type="radio" :value="a" v-model="chosenAction"
                        class="mt-1 text-pink-600 border-gray-300 focus:ring-pink-500" />
                    <span class="p-1.5 rounded-md shrink-0" :class="ACTION_META[a].iconBg">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ACTION_META[a].icon" />
                        </svg>
                    </span>
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ ACTION_META[a].label }}</span>
                        <span class="block text-xs text-gray-500">{{ ACTION_META[a].desc }}</span>
                    </span>
                </label>
            </fieldset>

            <!-- Dynamic panel -->
            <div v-if="chosenAction" class="mb-4">
                <FormErrorBanner :errors="activeErrors" class="mb-3" />

                <p v-if="chosenAction === ACTION.CREATE_AUTH" class="text-sm text-gray-600">
                    Se abrirá el formulario de autorización prellenado con la fecha y los horarios sugeridos
                    a partir de las checadas reales. Al aprobarse, esta anomalía se vinculará automáticamente.
                </p>

                <div v-else-if="chosenAction === ACTION.FIX_PUNCHES" class="bg-gray-50 rounded-md p-3 text-sm">
                    <p class="text-xs text-gray-500 mb-2">Checadas actuales del registro:</p>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-gray-500">Entrada</span>
                            <p class="font-medium text-gray-900">{{ recordCheckIn || '—' }}</p>
                        </div>
                        <div>
                            <span class="text-gray-500">Salida</span>
                            <p class="font-medium text-gray-900">{{ recordCheckOut || '—' }}</p>
                        </div>
                    </div>
                </div>

                <div v-else-if="chosenAction === ACTION.LINK_AUTH">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Autorización aprobada</label>
                    <p v-if="loadingLinkables" class="text-sm text-gray-500">Cargando autorizaciones…</p>
                    <SearchableSelect
                        v-else
                        v-model="linkAuthForm.authorization_id"
                        :options="linkableAuthorizations"
                        value-key="id"
                        label-key="label"
                        secondary-key="detail"
                        placeholder="Buscar autorización..."
                        :has-error="!!linkAuthForm.errors.authorization_id"
                    />
                </div>

                <div v-else-if="chosenAction === ACTION.LINK_INCIDENT">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Permiso / incidencia aprobada</label>
                    <p v-if="loadingLinkables" class="text-sm text-gray-500">Cargando permisos…</p>
                    <SearchableSelect
                        v-else
                        v-model="linkIncidentForm.incident_id"
                        :options="linkableIncidents"
                        value-key="id"
                        label-key="label"
                        secondary-key="detail"
                        placeholder="Buscar permiso..."
                        :has-error="!!linkIncidentForm.errors.incident_id"
                    />
                </div>

                <div v-else>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        {{ chosenAction === ACTION.DISMISS ? 'Motivo del descarte' : 'Justificación' }}
                        <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        v-model="notesModel"
                        rows="3"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': activeErrors.resolution_notes }"
                        :placeholder="chosenAction === ACTION.DISMISS
                            ? 'Explica por qué es un falso positivo (mínimo 5 caracteres)...'
                            : 'Describe la justificación (mínimo 5 caracteres)...'"
                    ></textarea>
                </div>

                <!-- Conditional 2FA (justify/dismiss only) -->
                <div v-if="needsTwoFactor" class="mt-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Código de verificación <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="twoFactorModel"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        class="w-48 text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': activeErrors.two_factor_code }"
                        placeholder="000000"
                    />
                </div>
            </div>

            <!-- Footer -->
            <div class="flex justify-end gap-3 border-t border-gray-200 pt-4">
                <button
                    type="button"
                    @click="$emit('close')"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                >
                    Cancelar
                </button>
                <button
                    v-if="chosenAction === ACTION.CREATE_AUTH"
                    type="button"
                    @click="goCreateAuthorization"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                >
                    Crear autorización
                </button>
                <button
                    v-else-if="chosenAction === ACTION.FIX_PUNCHES"
                    type="button"
                    @click="goFixPunches"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                >
                    Editar registro
                </button>
                <button
                    v-else
                    type="button"
                    :disabled="!chosenAction || submitDisabled"
                    @click="submit"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ activeProcessing ? 'Guardando...' : 'Confirmar' }}
                </button>
            </div>
        </div>
    </Modal>
</template>
