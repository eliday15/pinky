<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';
import { formatDate, formatDateTime } from '@/utils/date';

const props = defineProps({
    omissions: Object,
    stats: Object,
    filters: Object,
    employees: Array,
    departments: Array,
    reasonOptions: Object,
    can: Object,
});

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const reasonFilter = ref(props.filters.reason || '');
const employeeFilter = ref(props.filters.employee || '');
const departmentFilter = ref(props.filters.department || '');
const fromDate = ref(props.filters.from_date || '');
const toDate = ref(props.filters.to_date || '');

const rejectingId = ref(null);
const rejectionReason = ref('');

const applyFilters = debounce(() => {
    router.get(route('check-omissions.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        reason: reasonFilter.value || undefined,
        employee: employeeFilter.value || undefined,
        department: departmentFilter.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([statusFilter, reasonFilter, employeeFilter, departmentFilter, fromDate, toDate], applyFilters);
watch(search, debounce(() => applyFilters(), 300));

const clearFilters = () => {
    search.value = '';
    statusFilter.value = '';
    reasonFilter.value = '';
    employeeFilter.value = '';
    departmentFilter.value = '';
    fromDate.value = '';
    toDate.value = '';
    applyFilters();
};

const currentFilters = () => ({
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    reason: reasonFilter.value || undefined,
    employee: employeeFilter.value || undefined,
    department: departmentFilter.value || undefined,
    from_date: fromDate.value || undefined,
    to_date: toDate.value || undefined,
});

const approve = (id) => {
    router.post(route('check-omissions.approve', id), {}, { preserveScroll: true });
};

const openReject = (id) => {
    rejectingId.value = id;
    rejectionReason.value = '';
};

const cancelReject = () => {
    rejectingId.value = null;
    rejectionReason.value = '';
};

const submitReject = (id) => {
    router.post(route('check-omissions.reject', id), {
        rejection_reason: rejectionReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            rejectingId.value = null;
            rejectionReason.value = '';
        },
    });
};

const statusBadgeClass = (status) => {
    const map = {
        authorized: 'bg-amber-100 text-amber-800',
        approved: 'bg-green-100 text-green-800',
        rejected: 'bg-red-100 text-red-800',
    };
    return map[status] || 'bg-gray-100 text-gray-700';
};

const statusLabel = (status) => {
    const map = {
        authorized: 'Autorizada',
        approved: 'Aprobada',
        rejected: 'Rechazada',
    };
    return map[status] || status;
};
</script>

<template>
    <Head title="Omisiones de checada" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Omisiones de checada
            </h2>
        </template>

        <!-- Page Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Omisiones de checada</h1>
                <p class="text-gray-600">
                    Falta automática por checada incompleta (entrada o salida). El jefe autoriza y el administrador aprueba.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <a
                    :href="route('check-omissions.export', currentFilters())"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                >
                    Exportar
                </a>
                <button
                    v-if="can.create"
                    type="button"
                    @click="router.visit(route('check-omissions.create'))"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm"
                >
                    Nueva omisión
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-amber-100">
                        <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Autorizadas</p>
                        <p class="text-2xl font-bold text-amber-600">{{ stats.authorized }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Aprobadas</p>
                        <p class="text-2xl font-bold text-green-600">{{ stats.approved }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-red-100">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Rechazadas</p>
                        <p class="text-2xl font-bold text-red-600">{{ stats.rejected }}</p>
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
                        v-model="statusFilter"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option value="authorized">Autorizada</option>
                        <option value="approved">Aprobada</option>
                        <option value="rejected">Rechazada</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Motivo</label>
                    <select
                        v-model="reasonFilter"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="[key, label] in Object.entries(reasonOptions)" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                    <select
                        v-model="employeeFilter"
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
                        v-model="departmentFilter"
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

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Colaborador
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Fecha
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Motivo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Autorizó
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Aprobó
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Comentarios
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <template v-for="o in omissions.data" :key="o.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                        <span class="text-pink-600 text-sm font-medium">
                                            {{ o.employee?.full_name?.charAt(0) || '?' }}
                                        </span>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-900">{{ o.employee?.full_name }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ o.employee?.department?.name || '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ formatDate(o.work_date) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ reasonOptions[o.reason] || o.reason }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="[statusBadgeClass(o.status), 'px-2 py-1 text-xs font-medium rounded-full']">
                                    {{ statusLabel(o.status) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <template v-if="o.authorizedBy">
                                    <p class="text-sm text-gray-900">{{ o.authorizedBy.name }}</p>
                                    <p class="text-xs text-gray-400">{{ formatDateTime(o.authorized_at) }}</p>
                                </template>
                                <span v-else class="text-sm text-gray-400">-</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <template v-if="o.approvedBy">
                                    <p class="text-sm text-gray-900">{{ o.approvedBy.name }}</p>
                                    <p class="text-xs text-gray-400">{{ formatDateTime(o.approved_at) }}</p>
                                </template>
                                <template v-else-if="o.rejectedBy">
                                    <p class="text-sm text-red-600">{{ o.rejectedBy.name }}</p>
                                    <p class="text-xs text-gray-400">Rechazó</p>
                                </template>
                                <span v-else class="text-sm text-gray-400">-</span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs">
                                {{ o.comments || '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <template v-if="can.approve && o.status === 'authorized'">
                                    <button
                                        @click="approve(o.id)"
                                        class="text-green-600 hover:text-green-900"
                                    >
                                        Aprobar
                                    </button>
                                    <button
                                        @click="openReject(o.id)"
                                        class="text-red-600 hover:text-red-900"
                                    >
                                        Rechazar
                                    </button>
                                </template>
                            </td>
                        </tr>

                        <!-- Inline rejection form -->
                        <tr v-if="rejectingId === o.id" :key="`reject-${o.id}`">
                            <td colspan="9" class="px-6 py-4 bg-red-50">
                                <div class="flex flex-wrap items-end gap-4">
                                    <div class="flex-1 min-w-48">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Motivo de rechazo (opcional)
                                        </label>
                                        <input
                                            v-model="rejectionReason"
                                            type="text"
                                            placeholder="Especificar motivo..."
                                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                        />
                                    </div>
                                    <div class="flex space-x-2">
                                        <button
                                            @click="submitReject(o.id)"
                                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm"
                                        >
                                            Confirmar rechazo
                                        </button>
                                        <button
                                            @click="cancelReject"
                                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                                        >
                                            Cancelar
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="omissions.data.length === 0">
                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron omisiones de checada
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="omissions.links && omissions.links.length > 3" class="px-6 py-3 border-t border-gray-200">
                <nav class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando {{ omissions.from }} a {{ omissions.to }} de {{ omissions.total }}
                    </div>
                    <div class="flex space-x-2">
                        <template v-for="link in omissions.links" :key="link.label">
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
    </AppLayout>
</template>
