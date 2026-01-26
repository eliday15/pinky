<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: Object,
    users: Array,
    filters: Object,
    modules: Array,
    actions: Array,
});

const filters = ref({
    module: props.filters.module || '',
    action: props.filters.action || '',
    user_id: props.filters.user_id || '',
    from_date: props.filters.from_date || '',
    to_date: props.filters.to_date || '',
    search: props.filters.search || '',
});

const applyFilters = () => {
    router.get(route('audit-logs.index'), filters.value, {
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    filters.value = {
        module: '',
        action: '',
        user_id: '',
        from_date: '',
        to_date: '',
        search: '',
    };
    applyFilters();
};

const actionColors = {
    create: 'bg-green-100 text-green-800',
    update: 'bg-blue-100 text-blue-800',
    delete: 'bg-red-100 text-red-800',
    approve: 'bg-green-100 text-green-800',
    reject: 'bg-red-100 text-red-800',
    login: 'bg-indigo-100 text-indigo-800',
    logout: 'bg-gray-100 text-gray-800',
    sync: 'bg-purple-100 text-purple-800',
    export: 'bg-yellow-100 text-yellow-800',
};

const moduleLabels = {
    employees: 'Empleados',
    attendance: 'Asistencia',
    payroll: 'Nomina',
    incidents: 'Incidencias',
    authorizations: 'Autorizaciones',
    settings: 'Configuracion',
    auth: 'Autenticacion',
};

const actionLabels = {
    create: 'Crear',
    update: 'Actualizar',
    delete: 'Eliminar',
    approve: 'Aprobar',
    reject: 'Rechazar',
    login: 'Login',
    logout: 'Logout',
    sync: 'Sync',
    export: 'Exportar',
};
</script>

<template>
    <Head title="Auditoria" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Logs de Auditoria
            </h2>
        </template>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Modulo</label>
                    <select
                        v-model="filters.module"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="m in modules" :key="m.value" :value="m.value">
                            {{ m.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Accion</label>
                    <select
                        v-model="filters.action"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todas</option>
                        <option v-for="a in actions" :key="a.value" :value="a.value">
                            {{ a.label }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Usuario</label>
                    <select
                        v-model="filters.user_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">
                            {{ u.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                    <input
                        v-model="filters.from_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                    <input
                        v-model="filters.to_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    />
                </div>

                <div class="flex items-end">
                    <button
                        @click="clearFilters"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm"
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
                            Fecha/Hora
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Usuario
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Modulo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Accion
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Descripcion
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            IP
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Detalles
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ new Date(log.created_at).toLocaleString('es-MX') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ log.user?.name || 'Sistema' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ moduleLabels[log.module] || log.module }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[actionColors[log.action], 'px-2 py-1 text-xs rounded-full']">
                                {{ actionLabels[log.action] || log.action }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                            {{ log.description || `${log.auditable_type?.split('\\').pop() || ''} #${log.auditable_id || '-'}` }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ log.ip_address || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <Link
                                :href="route('audit-logs.show', log.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron logs de auditoria
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="logs.links.length > 3" class="px-6 py-3 border-t border-gray-200">
                <nav class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando {{ logs.from }} a {{ logs.to }} de {{ logs.total }}
                    </div>
                    <div class="flex space-x-1">
                        <template v-for="link in logs.links" :key="link.label">
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
