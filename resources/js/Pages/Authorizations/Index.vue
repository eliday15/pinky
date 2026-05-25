<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TwoFactorModal from '@/Components/TwoFactorModal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { formatDate } from '@/utils/date';
import SearchableSelect from '@/Components/SearchableSelect.vue';

const props = defineProps({
    authorizations: Object,
    employees: Array,
    departments: Array,
    pendingCount: Number,
    filters: Object,
    types: Array,
    can: Object,
});

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);
const showApproveModal = ref(false);
const approveAuthId = ref(null);

const filters = ref({
    status: props.filters.status || '',
    type: props.filters.type || '',
    employee: props.filters.employee || '',
    department: props.filters.department || '',
    from_date: props.filters.from_date || '',
    to_date: props.filters.to_date || '',
    search: props.filters.search || '',
});

/** Deduplicate types by value for the filter dropdown. */
const uniqueFilterTypes = computed(() => {
    const seen = new Set();
    return props.types.filter(t => {
        if (seen.has(t.value)) return false;
        seen.add(t.value);
        return true;
    });
});

const applyFilters = () => {
    router.get(route('authorizations.index'), filters.value, {
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.value = {
        status: '',
        type: '',
        employee: '',
        department: '',
        from_date: '',
        to_date: '',
        search: '',
    };
    applyFilters();
};

/** SearchableSelect updates the v-model directly without firing @change, so
 *  watch the employee filter and trigger the URL update from here. */
watch(() => filters.value.employee, () => applyFilters());

// Bulk selection
const selectedIds = ref([]);

const pendingRowIds = computed(() =>
    props.authorizations.data.filter(a => a.status === 'pending').map(a => a.id)
);

const allPendingSelected = computed(() =>
    pendingRowIds.value.length > 0 && pendingRowIds.value.every(id => selectedIds.value.includes(id))
);

const toggleSelectAll = (event) => {
    if (event.target.checked) {
        selectedIds.value = [...pendingRowIds.value];
    } else {
        selectedIds.value = [];
    }
};

const toggleSelect = (id) => {
    const idx = selectedIds.value.indexOf(id);
    if (idx >= 0) selectedIds.value.splice(idx, 1);
    else selectedIds.value.push(id);
};

// Reset selection when the underlying data set changes (e.g., filter/page change)
watch(() => props.authorizations.data, () => {
    selectedIds.value = [];
});

const showBulkApproveModal = ref(false);
const showBulkRejectModal = ref(false);

const bulkRejectForm = useForm({
    ids: [],
    rejection_reason: '',
    two_factor_code: '',
});

const handleBulkApprove = () => {
    if (selectedIds.value.length === 0) return;
    if (hasTwoFactor.value) {
        showBulkApproveModal.value = true;
    } else {
        router.post(route('authorizations.bulkApprove'), { ids: selectedIds.value }, {
            preserveScroll: true,
            onSuccess: () => { selectedIds.value = []; },
        });
    }
};

const openBulkRejectModal = () => {
    if (selectedIds.value.length === 0) return;
    bulkRejectForm.ids = [...selectedIds.value];
    bulkRejectForm.rejection_reason = '';
    bulkRejectForm.two_factor_code = '';
    bulkRejectForm.clearErrors();
    showBulkRejectModal.value = true;
};

const submitBulkReject = () => {
    bulkRejectForm.ids = [...selectedIds.value];
    bulkRejectForm.post(route('authorizations.bulkReject'), {
        preserveScroll: true,
        onSuccess: () => {
            showBulkRejectModal.value = false;
            selectedIds.value = [];
        },
    });
};

const rejectForm = useForm({
    rejection_reason: '',
    two_factor_code: '',
});

const showRejectModal = ref(false);
const selectedAuthorization = ref(null);

const handleApprove = (auth) => {
    if (hasTwoFactor.value) {
        approveAuthId.value = auth.id;
        showApproveModal.value = true;
    } else {
        router.post(route('authorizations.approve', auth.id));
    }
};

const openRejectModal = (auth) => {
    selectedAuthorization.value = auth;
    rejectForm.rejection_reason = '';
    rejectForm.two_factor_code = '';
    rejectForm.clearErrors();
    showRejectModal.value = true;
};

const submitReject = () => {
    rejectForm.post(route('authorizations.reject', selectedAuthorization.value.id), {
        onSuccess: () => {
            showRejectModal.value = false;
            selectedAuthorization.value = null;
        },
    });
};

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
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
</script>

<template>
    <Head title="Autorizaciones" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Autorizaciones
                <span v-if="pendingCount > 0" class="ml-2 px-2 py-1 text-xs bg-yellow-500 text-white rounded-full">
                    {{ pendingCount }} pendientes
                </span>
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion de Autorizaciones</h1>
                <p class="text-gray-600">Horas extra, veladas y conceptos de compensacion</p>
            </div>
            <div v-if="can.create" class="flex space-x-2">
                <Link
                    :href="route('authorizations.createBulk')"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                >
                    Masiva
                </Link>
                <Link
                    :href="route('authorizations.create')"
                    class="inline-flex items-center px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                >
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                    Nueva
                </Link>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="Nombre empleado..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @keyup.enter="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select
                        v-model="filters.status"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option value="pending">Pendiente</option>
                        <option value="approved">Aprobado</option>
                        <option value="rejected">Rechazado</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                    <select
                        v-model="filters.type"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="type in uniqueFilterTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                    <SearchableSelect
                        v-model="filters.employee"
                        :options="employees"
                        value-key="id"
                        label-key="full_name"
                        secondary-key="employee_number"
                        placeholder="Todos"
                        allow-clear
                        @update:model-value="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                    <SearchableSelect
                        v-model="filters.department"
                        :options="departments"
                        value-key="id"
                        label-key="name"
                        placeholder="Todos"
                        allow-clear
                        @update:model-value="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                    <input
                        v-model="filters.from_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                    <input
                        v-model="filters.to_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    />
                </div>

                <div class="flex items-end">
                    <button
                        @click="clearFilters"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800"
                    >
                        Limpiar
                    </button>
                </div>
            </div>
        </div>

        <!-- Bulk Action Toolbar -->
        <div
            v-if="selectedIds.length > 0 && (can.approve || can.reject)"
            class="bg-pink-50 border border-pink-200 rounded-lg shadow p-4 mb-4 flex flex-wrap items-center justify-between gap-3"
        >
            <div class="text-sm font-medium text-pink-900">
                {{ selectedIds.length }} {{ selectedIds.length === 1 ? 'autorizacion seleccionada' : 'autorizaciones seleccionadas' }}
            </div>
            <div class="flex space-x-2">
                <button
                    v-if="can.approve"
                    @click="handleBulkApprove"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                >
                    Aprobar seleccionados
                </button>
                <button
                    v-if="can.reject"
                    @click="openBulkRejectModal"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
                >
                    Rechazar seleccionados
                </button>
                <button
                    @click="selectedIds = []"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                >
                    Limpiar
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input
                                v-if="(can.approve || can.reject) && pendingRowIds.length > 0"
                                type="checkbox"
                                :checked="allPendingSelected"
                                @change="toggleSelectAll"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Empleado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Tipo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Fecha
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Detalle
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="auth in authorizations.data" :key="auth.id" class="hover:bg-gray-50">
                        <td class="px-4 py-4 whitespace-nowrap">
                            <input
                                v-if="auth.status === 'pending' && (can.approve || can.reject)"
                                type="checkbox"
                                :checked="selectedIds.includes(auth.id)"
                                @change="toggleSelect(auth.id)"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">
                                {{ auth.employee?.full_name }}
                            </div>
                            <div class="text-sm text-gray-500">
                                {{ auth.employee?.department?.name }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-sm text-gray-900">
                                {{ auth.compensation_type?.name || typeLabels[auth.type] || auth.type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">
                                {{ formatDate(auth.date) }}
                            </div>
                            <div v-if="auth.compensation_type?.application_mode === 'per_hour' && auth.start_time" class="text-xs text-gray-500">
                                {{ auth.start_time?.substring(0,5) }} - {{ auth.end_time?.substring(0,5) }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <template v-if="auth.compensation_type?.application_mode === 'per_hour'">
                                <span class="font-medium">{{ auth.hours || 0 }}</span>
                                <span class="text-gray-500 text-xs ml-1">horas</span>
                            </template>
                            <template v-else-if="auth.compensation_type?.application_mode === 'per_day'">
                                <span class="font-medium">{{ auth.hours || 0 }}</span>
                                <span class="text-gray-500 text-xs ml-1">{{ auth.hours == 1 ? 'dia' : 'dias' }}</span>
                            </template>
                            <template v-else-if="auth.compensation_type?.application_mode === 'one_time'">
                                <span class="font-medium">{{ auth.hours || 1 }}</span>
                                <span class="text-gray-500 text-xs ml-1">{{ auth.hours == 1 ? 'bono' : 'bonos' }}</span>
                            </template>
                            <template v-else>
                                <span class="font-medium">{{ auth.hours || '-' }}</span>
                            </template>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[auth.status], 'px-2 py-1 text-xs rounded-full']">
                                {{ statusLabels[auth.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                            <Link
                                :href="route('authorizations.show', auth.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                            <template v-if="auth.status === 'pending'">
                                <button
                                    v-if="can.approve"
                                    @click="handleApprove(auth)"
                                    class="text-green-600 hover:text-green-900"
                                >
                                    Aprobar
                                </button>
                                <button
                                    v-if="can.reject"
                                    @click="openRejectModal(auth)"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    Rechazar
                                </button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="authorizations.data.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron autorizaciones
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="authorizations.links.length > 3" class="px-6 py-3 border-t border-gray-200">
                <nav class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando {{ authorizations.from }} a {{ authorizations.to }} de {{ authorizations.total }}
                    </div>
                    <div class="flex space-x-2">
                        <template v-for="link in authorizations.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                :class="[
                                    'px-3 py-1 rounded text-sm',
                                    link.active
                                        ? 'bg-pink-600 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-400"
                            />
                        </template>
                    </div>
                </nav>
            </div>
        </div>

        <!-- Approve 2FA Modal -->
        <TwoFactorModal
            v-if="approveAuthId"
            :show="showApproveModal"
            :action="route('authorizations.approve', approveAuthId)"
            method="post"
            title="Aprobar Autorizacion"
            message="Ingresa tu codigo de verificacion para aprobar esta autorizacion."
            @close="showApproveModal = false; approveAuthId = null;"
        />

        <!-- Bulk Approve 2FA Modal -->
        <TwoFactorModal
            :show="showBulkApproveModal"
            :action="route('authorizations.bulkApprove')"
            method="post"
            title="Aprobar autorizaciones seleccionadas"
            :message="`Ingresa tu codigo de verificacion para aprobar ${selectedIds.length} autorizaciones.`"
            :extra-data="{ ids: selectedIds }"
            @close="showBulkApproveModal = false"
            @success="selectedIds = []"
        />

        <!-- Bulk Reject Modal -->
        <div v-if="showBulkRejectModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showBulkRejectModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Rechazar {{ selectedIds.length }} autorizaciones
                    </h3>
                    <form @submit.prevent="submitBulkReject">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Razon del rechazo *
                            </label>
                            <textarea
                                v-model="bulkRejectForm.rejection_reason"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                required
                            ></textarea>
                            <p v-if="bulkRejectForm.errors.rejection_reason" class="mt-1 text-sm text-red-600">
                                {{ bulkRejectForm.errors.rejection_reason }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo de verificacion *
                            </label>
                            <input
                                v-model="bulkRejectForm.two_factor_code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                class="w-full text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="000000"
                            />
                            <p v-if="bulkRejectForm.errors.two_factor_code" class="mt-1 text-sm text-red-600">
                                {{ bulkRejectForm.errors.two_factor_code }}
                            </p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="showBulkRejectModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="bulkRejectForm.processing"
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                            >
                                Rechazar
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
