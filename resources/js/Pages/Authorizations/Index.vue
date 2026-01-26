<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    authorizations: Object,
    employees: Array,
    pendingCount: Number,
    filters: Object,
    types: Array,
    can: Object,
});

const filters = ref({
    status: props.filters.status || '',
    type: props.filters.type || '',
    employee: props.filters.employee || '',
    search: props.filters.search || '',
});

const applyFilters = () => {
    router.get(route('authorizations.index'), filters.value, {
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.value = { status: '', type: '', employee: '', search: '' };
    applyFilters();
};

const rejectForm = useForm({
    rejection_reason: '',
});

const showRejectModal = ref(false);
const selectedAuthorization = ref(null);

const openRejectModal = (auth) => {
    selectedAuthorization.value = auth;
    rejectForm.rejection_reason = '';
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
    exit_permission: 'Permiso Salida',
    entry_permission: 'Permiso Entrada',
    schedule_change: 'Cambio Horario',
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
                <p class="text-gray-600">Horas extra, veladas, permisos y cambios de horario</p>
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
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
                        <option v-for="type in types" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                    <select
                        v-model="filters.employee"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                            {{ emp.full_name }}
                        </option>
                    </select>
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

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
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
                            Horas
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
                                {{ typeLabels[auth.type] || auth.type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">
                                {{ new Date(auth.date).toLocaleDateString('es-MX') }}
                            </div>
                            <div v-if="auth.start_time" class="text-sm text-gray-500">
                                {{ auth.start_time }} - {{ auth.end_time }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ auth.hours ? `${auth.hours}h` : '-' }}
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
                                <Link
                                    v-if="can.approve"
                                    :href="route('authorizations.approve', auth.id)"
                                    method="post"
                                    as="button"
                                    class="text-green-600 hover:text-green-900"
                                >
                                    Aprobar
                                </Link>
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
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
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
