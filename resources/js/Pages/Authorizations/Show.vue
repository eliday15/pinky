<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { formatDate as fmtDate, formatDateTime } from '@/utils/date';

const props = defineProps({
    authorization: Object,
    punches: Object,
    // Conteo por unidades (Almacén PT): { units, unit_hours, worked_hours, label }
    // o null cuando el depto no cuenta por unidades.
    weekendUnits: { type: Object, default: null },
    can: Object,
});

/** Format a punch time ('HH:MM:SS', 'HH:MM' or an ISO datetime) as 'HH:MM'. */
const fmtTime = (t) => {
    if (!t) return '—';
    const m = String(t).match(/(\d{2}):(\d{2})/);
    return m ? `${m[1]}:${m[2]}` : String(t);
};

/** Human label for a raw punch type stored by the ZKTeco sync. */
const punchTypeLabel = (type) => ({
    in: 'entrada',
    out: 'salida',
    lunch_out: 'sale a comer',
    lunch_in: 'regresa de comer',
    punch: 'marca',
}[type] || type || 'marca');

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);

/** Hour-based types use the escalonado select for partial approval; everything
 *  else (per_day / one_time quantities) uses a plain number input. */
const isHoursType = computed(() => ['overtime', 'night_shift'].includes(props.authorization.type));

/** Escalonado ladder: every half hour from 0.5h to 24h. */
const hourStepOptions = computed(() => {
    const opts = [];
    for (let i = 1; i <= 48; i++) {
        const v = i / 2;
        opts.push({ value: v.toFixed(2), label: String(v) });
    }
    return opts;
});

const isAlreadyApproved = computed(() => props.authorization.status === 'approved');
const approveLabel = computed(() => (isAlreadyApproved.value ? 'Modificar aprobación' : 'Aprobar'));

/** Default the approve dialog to the currently requested/approved hours, as a
 *  canonical two-decimal string so the select pre-selects the matching option. */
const defaultApproveHours = () => {
    const n = Number(props.authorization.hours);
    return n > 0 ? n.toFixed(2) : '';
};

const showApproveModal = ref(false);
const approveForm = useForm({
    hours: defaultApproveHours(),
    two_factor_code: '',
});

const openApprove = () => {
    approveForm.hours = defaultApproveHours();
    approveForm.two_factor_code = '';
    approveForm.clearErrors();
    showApproveModal.value = true;
};

const submitApprove = () => {
    approveForm.post(route('authorizations.approve', props.authorization.id), {
        preserveScroll: true,
        onSuccess: () => {
            showApproveModal.value = false;
        },
    });
};

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
    holiday_worked: 'Festivo Trabajado',
    special: 'Especial',
};

const rejectForm = useForm({
    rejection_reason: '',
    two_factor_code: '',
});

const showRejectModal = ref(false);

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
                                        {{ fmtDate(authorization.date, {
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
                                <div v-if="weekendUnits">
                                    <dt class="text-sm font-medium text-gray-500">Unidades (Almacén PT)</dt>
                                    <dd class="mt-1 text-sm font-semibold text-pink-700">
                                        {{ weekendUnits.units }} {{ weekendUnits.label }}
                                    </dd>
                                    <dd class="mt-0.5 text-xs text-gray-500">
                                        {{ weekendUnits.worked_hours }} h trabajadas ÷ {{ weekendUnits.unit_hours }} h por unidad (se trunca, no se redondea)
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

                <!-- Checadas originales del sistema -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Checadas originales del sistema</h3>
                    <template v-if="punches && punches.found">
                        <dl class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Entrada</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ fmtTime(punches.check_in) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Salida</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ fmtTime(punches.check_out) }}</dd>
                            </div>
                        </dl>
                        <div v-if="punches.raw && punches.raw.length">
                            <p class="text-sm font-medium text-gray-500 mb-2">Todas las marcas del día</p>
                            <ul class="flex flex-wrap gap-2">
                                <li
                                    v-for="(p, i) in punches.raw"
                                    :key="i"
                                    class="px-3 py-1 rounded-full bg-gray-100 text-sm text-gray-800"
                                >
                                    {{ fmtTime(p.time) }}
                                    <span class="text-gray-400">· {{ punchTypeLabel(p.type) }}</span>
                                </li>
                            </ul>
                        </div>
                        <p v-else class="text-sm text-gray-500">
                            No hay marcas individuales registradas para este día.
                        </p>
                        <p v-if="punches.is_weekend_work || punches.is_holiday" class="mt-3 text-xs text-gray-500">
                            <span v-if="punches.is_weekend_work">Trabajo de fin de semana. </span>
                            <span v-if="punches.is_holiday">Día festivo.</span>
                        </p>
                    </template>
                    <p v-else class="text-sm text-gray-500">
                        No se encontraron checadas del sistema para este día.
                    </p>
                </div>

                <!-- Approval Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tramite</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Solicitado por</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ authorization.requested_by?.name || '-' }}
                                <span class="text-gray-500 ml-2">
                                    {{ formatDateTime(authorization.created_at) }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="authorization.approved_by">
                            <dt class="text-sm font-medium text-gray-500">
                                {{ authorization.status === 'approved' ? 'Aprobado por' : 'Rechazado por' }}
                            </dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ authorization.approved_by?.name || '-' }}
                                <span v-if="authorization.approved_at" class="text-gray-500 ml-2">
                                    {{ formatDateTime(authorization.approved_at) }}
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
                    <div class="space-x-2">
                        <button
                            v-if="can.approve"
                            @click="openApprove"
                            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                        >
                            {{ approveLabel }}
                        </button>
                        <button
                            v-if="can.reject && ['pending', 'approved'].includes(authorization.status)"
                            @click="showRejectModal = true"
                            class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                        >
                            Rechazar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve Modal (with partial-hours adjustment) -->
        <div v-if="showApproveModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showApproveModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-1">
                        {{ approveLabel }}
                    </h3>
                    <p class="text-sm text-gray-500 mb-4">
                        {{ isAlreadyApproved
                            ? 'Ajusta las horas aprobadas y vuelve a confirmar.'
                            : 'Confirma la aprobación. Puedes aprobar una cantidad parcial.' }}
                    </p>
                    <form @submit.prevent="submitApprove">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                {{ isHoursType ? 'Horas a aprobar' : 'Cantidad a aprobar' }}
                            </label>
                            <p v-if="authorization.hours" class="text-xs text-gray-500 mb-1">
                                Solicitado: {{ authorization.hours }}<span v-if="isHoursType">h</span>
                            </p>
                            <select
                                v-if="isHoursType"
                                v-model="approveForm.hours"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': approveForm.errors.hours }"
                            >
                                <option value="">Sin cambio</option>
                                <option v-for="opt in hourStepOptions" :key="opt.value" :value="opt.value">{{ opt.label }}h</option>
                            </select>
                            <input
                                v-else
                                v-model="approveForm.hours"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="Sin cambio"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': approveForm.errors.hours }"
                            />
                            <p v-if="approveForm.errors.hours" class="mt-1 text-sm text-red-600">
                                {{ approveForm.errors.hours }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo de verificacion *
                            </label>
                            <input
                                v-model="approveForm.two_factor_code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                :class="{ 'border-red-500': approveForm.errors.two_factor_code }"
                                class="w-full text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="000000"
                            />
                            <p v-if="approveForm.errors.two_factor_code" class="mt-1 text-sm text-red-600">
                                {{ approveForm.errors.two_factor_code }}
                            </p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="showApproveModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="approveForm.processing"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                            >
                                {{ approveForm.processing ? 'Guardando...' : approveLabel }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reject Modal -->
        <div v-if="showRejectModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showRejectModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Rechazar Autorizacion
                    </h3>
                    <p v-if="isAlreadyApproved" class="text-sm text-amber-600 mb-4">
                        Esta autorización ya estaba aprobada. Al rechazarla se revertirá:
                        se recalcula la asistencia, se reabren las anomalías vinculadas y
                        se marca la nómina del periodo para recálculo.
                    </p>
                    <form @submit.prevent="submitReject">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Razon del rechazo *
                            </label>
                            <textarea
                                v-model="rejectForm.rejection_reason"
                                rows="3"
                                :class="{ 'border-red-500': rejectForm.errors.rejection_reason }"
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
                                :class="{ 'border-red-500': rejectForm.errors.two_factor_code }"
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
