<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    anomalies: Object,
    stats: Object,
    filters: Object,
    employees: Array,
    departments: Array,
    anomalyTypes: Array,
    can: Object,
});

const hasTwoFactor = computed(() => usePage().props.auth.has_two_factor);

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'open');
const severity = ref(props.filters.severity || '');
const anomalyType = ref(props.filters.anomaly_type || '');
const employee = ref(props.filters.employee || '');
const department = ref(props.filters.department || '');
const fromDate = ref(props.filters.from_date || '');
const toDate = ref(props.filters.to_date || '');

const selectedIds = ref([]);
const bulkAction = ref('');
const bulkNotes = ref('');
const showBulkNotes = ref(false);

const allSelected = computed({
    get: () => props.anomalies.data.length > 0 && selectedIds.value.length === props.anomalies.data.length,
    set: (value) => {
        selectedIds.value = value ? props.anomalies.data.map(a => a.id) : [];
    },
});

const applyFilters = debounce(() => {
    router.get(route('anomalies.index'), {
        search: search.value || undefined,
        status: status.value || undefined,
        severity: severity.value || undefined,
        anomaly_type: anomalyType.value || undefined,
        employee: employee.value || undefined,
        department: department.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([status, severity, anomalyType, employee, department, fromDate, toDate], applyFilters);
watch(search, debounce(() => applyFilters(), 300));

const clearFilters = () => {
    search.value = '';
    status.value = 'open';
    severity.value = '';
    anomalyType.value = '';
    employee.value = '';
    department.value = '';
    fromDate.value = '';
    toDate.value = '';
    applyFilters();
};

const severityColors = {
    critical: 'bg-red-100 text-red-800',
    warning: 'bg-yellow-100 text-yellow-800',
    info: 'bg-blue-100 text-blue-800',
};

const severityLabels = {
    critical: 'Critica',
    warning: 'Advertencia',
    info: 'Informativa',
};

const statusColors = {
    open: 'bg-yellow-100 text-yellow-800',
    resolved: 'bg-green-100 text-green-800',
    dismissed: 'bg-gray-100 text-gray-800',
    linked: 'bg-blue-100 text-blue-800',
};

const statusLabels = {
    open: 'Abierta',
    resolved: 'Resuelta',
    dismissed: 'Descartada',
    linked: 'Vinculada',
};

const typeIcons = {
    missing_check_in: 'M11 16l-4-4m0 0l4-4m-4 4h14',
    missing_check_out: 'M17 16l4-4m0 0l-4-4m4 4H3',
    late_arrival: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    early_departure: 'M17 16l4-4m0 0l-4-4m4 4H7',
    excessive_overtime: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    unscheduled_attendance: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    schedule_deviation: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
    missing_attendance: 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636',
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-MX');
};

const truncate = (text, length) => {
    if (!text) return '-';
    return text.length > length ? text.substring(0, length) + '...' : text;
};

/* ----- Modals for individual actions ----- */
const showResolveModal = ref(false);
const showDismissModal = ref(false);
const selectedAnomaly = ref(null);

const resolveForm = useForm({
    resolution_notes: '',
    two_factor_code: '',
});

const dismissForm = useForm({
    resolution_notes: '',
    two_factor_code: '',
});

const openResolveModal = (anomaly) => {
    selectedAnomaly.value = anomaly;
    resolveForm.resolution_notes = '';
    resolveForm.two_factor_code = '';
    resolveForm.clearErrors();
    showResolveModal.value = true;
};

const openDismissModal = (anomaly) => {
    selectedAnomaly.value = anomaly;
    dismissForm.resolution_notes = '';
    dismissForm.two_factor_code = '';
    dismissForm.clearErrors();
    showDismissModal.value = true;
};

const submitResolve = () => {
    resolveForm.post(route('anomalies.resolve', selectedAnomaly.value.id), {
        onSuccess: () => {
            showResolveModal.value = false;
            selectedAnomaly.value = null;
        },
    });
};

const submitDismiss = () => {
    dismissForm.post(route('anomalies.dismiss', selectedAnomaly.value.id), {
        onSuccess: () => {
            showDismissModal.value = false;
            selectedAnomaly.value = null;
        },
    });
};

/* ----- Bulk actions ----- */
const bulkTwoFactorCode = ref('');

const startBulkAction = (action) => {
    bulkAction.value = action;
    bulkNotes.value = '';
    bulkTwoFactorCode.value = '';
    showBulkNotes.value = true;
};

const submitBulkAction = () => {
    const routeName = bulkAction.value === 'resolve' ? 'anomalies.bulk-resolve' : 'anomalies.bulk-dismiss';
    const data = {
        anomaly_ids: selectedIds.value,
        resolution_notes: bulkNotes.value,
    };
    if (hasTwoFactor.value) {
        data.two_factor_code = bulkTwoFactorCode.value;
    }
    router.post(route(routeName), data, {
        onSuccess: () => {
            selectedIds.value = [];
            bulkAction.value = '';
            bulkNotes.value = '';
            bulkTwoFactorCode.value = '';
            showBulkNotes.value = false;
        },
    });
};

const cancelBulkAction = () => {
    bulkAction.value = '';
    bulkNotes.value = '';
    bulkTwoFactorCode.value = '';
    showBulkNotes.value = false;
};
</script>

<template>
    <Head title="Anomalias" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Anomalias
                <span v-if="stats.open > 0" class="ml-2 px-2 py-1 text-xs bg-yellow-500 text-white rounded-full">
                    {{ stats.open }} abiertas
                </span>
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion de Anomalias</h1>
                <p class="text-gray-600">Deteccion y resolucion de anomalias de asistencia</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-gray-100">
                        <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Total Abiertas</p>
                        <p class="text-2xl font-bold text-gray-900">{{ stats.open }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-red-100">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Criticas</p>
                        <p class="text-2xl font-bold text-red-600">{{ stats.critical }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Advertencias</p>
                        <p class="text-2xl font-bold text-yellow-600">{{ stats.warning }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Informativas</p>
                        <p class="text-2xl font-bold text-blue-600">{{ stats.info }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre empleado..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @keyup.enter="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select
                        v-model="status"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="open">Abiertas</option>
                        <option value="">Todas</option>
                        <option value="resolved">Resueltas</option>
                        <option value="dismissed">Descartadas</option>
                        <option value="linked">Vinculadas</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Severidad</label>
                    <select
                        v-model="severity"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todas</option>
                        <option value="critical">Critica</option>
                        <option value="warning">Advertencia</option>
                        <option value="info">Informativa</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Anomalia</label>
                    <select
                        v-model="anomalyType"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="t in anomalyTypes" :key="t.value" :value="t.value">
                            {{ t.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                    <select
                        v-model="employee"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                            {{ emp.full_name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                    <select
                        v-model="department"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                    <input
                        v-model="fromDate"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                    <input
                        v-model="toDate"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    />
                </div>
            </div>

            <div class="mt-4 flex justify-end">
                <button
                    @click="clearFilters"
                    class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200"
                >
                    Limpiar filtros
                </button>
            </div>
        </div>

        <!-- Bulk Actions Bar -->
        <div v-if="selectedIds.length > 0" class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <label class="flex items-center">
                    <input
                        type="checkbox"
                        v-model="allSelected"
                        class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                    />
                    <span class="ml-2 text-sm text-gray-700">
                        {{ selectedIds.length }} seleccionada(s)
                    </span>
                </label>

                <button
                    v-if="can.resolve"
                    @click="startBulkAction('resolve')"
                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm"
                >
                    Resolver seleccionadas
                </button>

                <button
                    v-if="can.dismiss"
                    @click="startBulkAction('dismiss')"
                    class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 text-sm"
                >
                    Descartar seleccionadas
                </button>
            </div>

            <div v-if="showBulkNotes" class="mt-4 space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Notas de resolucion {{ bulkAction === 'resolve' ? '(Resolver)' : '(Descartar)' }}
                    </label>
                    <textarea
                        v-model="bulkNotes"
                        rows="2"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        placeholder="Ingrese notas de resolucion..."
                    ></textarea>
                </div>
                <div v-if="hasTwoFactor">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Codigo de verificacion
                    </label>
                    <input
                        v-model="bulkTwoFactorCode"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        class="w-48 text-center text-lg tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        placeholder="000000"
                    />
                </div>
                <div class="flex space-x-2">
                    <button
                        @click="submitBulkAction"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm"
                    >
                        Confirmar
                    </button>
                    <button
                        @click="cancelBulkAction"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                    >
                        Cancelar
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input
                                type="checkbox"
                                v-model="allSelected"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Fecha
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Empleado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Tipo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Severidad
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Descripcion
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="anomaly in anomalies.data" :key="anomaly.id" class="hover:bg-gray-50">
                        <td class="px-4 py-4">
                            <input
                                type="checkbox"
                                :value="anomaly.id"
                                v-model="selectedIds"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ formatDate(anomaly.work_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ anomaly.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ anomaly.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ anomaly.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="typeIcons[anomaly.type] || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'" />
                                </svg>
                                <span class="text-sm text-gray-900">{{ anomaly.type_name }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[severityColors[anomaly.severity], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ severityLabels[anomaly.severity] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs">
                            {{ truncate(anomaly.description, 60) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[anomaly.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[anomaly.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                            <Link
                                :href="route('anomalies.show', anomaly.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                            <template v-if="anomaly.status === 'open'">
                                <button
                                    v-if="can.resolve"
                                    @click="openResolveModal(anomaly)"
                                    class="text-green-600 hover:text-green-900"
                                >
                                    Resolver
                                </button>
                                <button
                                    v-if="can.dismiss"
                                    @click="openDismissModal(anomaly)"
                                    class="text-gray-600 hover:text-gray-900"
                                >
                                    Descartar
                                </button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="anomalies.data.length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron anomalias
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="anomalies.links.length > 3" class="px-6 py-3 border-t border-gray-200">
                <nav class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando {{ anomalies.from }} a {{ anomalies.to }} de {{ anomalies.total }}
                    </div>
                    <div class="flex space-x-2">
                        <template v-for="link in anomalies.links" :key="link.label">
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

        <!-- Resolve Modal -->
        <div v-if="showResolveModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showResolveModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Resolver Anomalia
                    </h3>
                    <p class="text-sm text-gray-600 mb-4">
                        {{ selectedAnomaly?.type_name }} - {{ selectedAnomaly?.employee?.full_name }}
                    </p>
                    <form @submit.prevent="submitResolve">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Notas de resolucion
                            </label>
                            <textarea
                                v-model="resolveForm.resolution_notes"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="Describa como se resolvio esta anomalia..."
                            ></textarea>
                            <p v-if="resolveForm.errors.resolution_notes" class="mt-1 text-sm text-red-600">
                                {{ resolveForm.errors.resolution_notes }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo de verificacion
                            </label>
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
                        <div class="flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="showResolveModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="resolveForm.processing"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                            >
                                Resolver
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Dismiss Modal -->
        <div v-if="showDismissModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showDismissModal = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        Descartar Anomalia
                    </h3>
                    <p class="text-sm text-gray-600 mb-4">
                        {{ selectedAnomaly?.type_name }} - {{ selectedAnomaly?.employee?.full_name }}
                    </p>
                    <form @submit.prevent="submitDismiss">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Motivo del descarte
                            </label>
                            <textarea
                                v-model="dismissForm.resolution_notes"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                placeholder="Indique por que se descarta esta anomalia..."
                            ></textarea>
                            <p v-if="dismissForm.errors.resolution_notes" class="mt-1 text-sm text-red-600">
                                {{ dismissForm.errors.resolution_notes }}
                            </p>
                        </div>
                        <div v-if="hasTwoFactor" class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo de verificacion
                            </label>
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
                        <div class="flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="showDismissModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="dismissForm.processing"
                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50"
                            >
                                Descartar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
