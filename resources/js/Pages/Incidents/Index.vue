<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    incidents: Object,
    incidentTypes: Array,
    employees: Array,
    pendingCount: Number,
    filters: Object,
    can: Object,
});

const status = ref(props.filters.status || '');
const type = ref(props.filters.type || '');
const employee = ref(props.filters.employee || '');
const search = ref(props.filters.search || '');

const applyFilters = debounce(() => {
    router.get(route('incidents.index'), {
        status: status.value || undefined,
        type: type.value || undefined,
        employee: employee.value || undefined,
        search: search.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([status, type, employee, search], applyFilters);

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
    return new Date(date).toLocaleDateString('es-MX');
};

const approveIncident = (incident) => {
    if (confirm('¿Aprobar esta incidencia?')) {
        router.post(route('incidents.approve', incident.id));
    }
};

const rejectIncident = (incident) => {
    const reason = prompt('Motivo del rechazo:');
    if (reason) {
        router.post(route('incidents.reject', incident.id), {
            rejection_reason: reason,
        });
    }
};

const deleteIncident = (incident) => {
    if (confirm('¿Eliminar esta incidencia?')) {
        router.delete(route('incidents.destroy', incident.id));
    }
};
</script>

<template>
    <Head title="Incidencias" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Incidencias
                <span v-if="pendingCount > 0" class="ml-2 px-2 py-1 text-xs bg-yellow-500 text-white rounded-full">
                    {{ pendingCount }} pendientes
                </span>
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion de Incidencias</h1>
                <p class="text-gray-600">Vacaciones, permisos, incapacidades y faltas</p>
            </div>
            <div v-if="can?.create" class="flex space-x-2">
                <Link
                    :href="route('incidents.createBulk')"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                >
                    Masiva
                </Link>
                <Link
                    :href="route('incidents.create')"
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
                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar empleado..."
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                />
                <select
                    v-model="status"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Todos los estados</option>
                    <option value="pending">Pendiente</option>
                    <option value="approved">Aprobada</option>
                    <option value="rejected">Rechazada</option>
                </select>
                <select
                    v-model="type"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Todos los tipos</option>
                    <option v-for="t in incidentTypes" :key="t.id" :value="t.id">
                        {{ t.name }}
                    </option>
                </select>
                <select
                    v-model="employee"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Todos los empleados</option>
                    <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                        {{ emp.full_name }}
                    </option>
                </select>
                <button
                    @click="status = ''; type = ''; employee = ''; search = '';"
                    class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200"
                >
                    Limpiar
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Periodo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dias</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="incident in incidents.data" :key="incident.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ incident.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ incident.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ incident.employee?.department?.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span
                                class="px-2 py-1 text-xs font-medium rounded-full"
                                :style="{ backgroundColor: incident.incident_type?.color + '20', color: incident.incident_type?.color }"
                            >
                                {{ incident.incident_type?.name }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ formatDate(incident.start_date) }} - {{ formatDate(incident.end_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                            {{ incident.days_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[incident.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[incident.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                            <template v-if="incident.status === 'pending'">
                                <button
                                    @click="approveIncident(incident)"
                                    class="text-green-600 hover:text-green-900"
                                >
                                    Aprobar
                                </button>
                                <button
                                    @click="rejectIncident(incident)"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    Rechazar
                                </button>
                            </template>
                            <Link
                                :href="route('incidents.edit', incident.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Editar
                            </Link>
                            <button
                                @click="deleteIncident(incident)"
                                class="text-gray-600 hover:text-gray-900"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="incidents.data.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            No hay incidencias registradas
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="incidents.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ incidents.from }} a {{ incidents.to }} de {{ incidents.total }}
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in incidents.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active ? 'bg-pink-600 text-white' : link.url ? 'bg-gray-100 hover:bg-gray-200' : 'bg-gray-50 text-gray-400'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
