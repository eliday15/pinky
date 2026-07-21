<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatDateTime } from '@/utils/date';

const props = defineProps({
    log: Object,
    changes: Array,
    metadata: Array,
    related: Array,
});

const actionColors = {
    create: 'bg-green-100 text-green-800',
    update: 'bg-blue-100 text-blue-800',
    delete: 'bg-red-100 text-red-800',
    approve: 'bg-green-100 text-green-800',
    reject: 'bg-red-100 text-red-800',
    cancel: 'bg-red-100 text-red-800',
    recalculate: 'bg-blue-100 text-blue-800',
    close: 'bg-emerald-100 text-emerald-800',
    reopen: 'bg-amber-100 text-amber-800',
    pay: 'bg-emerald-100 text-emerald-800',
    import: 'bg-purple-100 text-purple-800',
    resolve: 'bg-amber-100 text-amber-800',
    login: 'bg-indigo-100 text-indigo-800',
    logout: 'bg-gray-100 text-gray-800',
    login_failed: 'bg-red-100 text-red-800',
    sync: 'bg-purple-100 text-purple-800',
    export: 'bg-yellow-100 text-yellow-800',
};

const actionLabels = {
    create: 'Crear',
    update: 'Actualizar',
    delete: 'Eliminar',
    approve: 'Aprobar',
    reject: 'Rechazar',
    cancel: 'Cancelar',
    recalculate: 'Recalcular',
    close: 'Cerrar',
    reopen: 'Reabrir',
    pay: 'Pagar',
    import: 'Importar',
    resolve: 'Resolver',
    login: 'Iniciar sesion',
    logout: 'Cerrar sesion',
    login_failed: 'Intento fallido de sesion',
    sync: 'Sincronizar',
    export: 'Exportar',
};

const moduleLabels = {
    employees: 'Empleados',
    attendance: 'Asistencia',
    payroll: 'Nomina',
    incidents: 'Incidencias',
    authorizations: 'Autorizaciones',
    settings: 'Configuracion',
    auth: 'Autenticacion',
    users: 'Usuarios y permisos',
    schedules: 'Horarios',
    check_omissions: 'Omisiones de checada',
    breakfasts: 'Desayunos',
    vacations: 'Vacaciones',
    cash: 'Pagos en efectivo',
    anomalies: 'Anomalias',
    catalogs: 'Catalogos',
    reports: 'Reportes',
    departments: 'Departamentos',
    positions: 'Puestos',
    compensation_types: 'Conceptos de pago',
};

const formatValue = (value) => {
    if (value === null || value === undefined || value === '') return '(vacio)';
    if (typeof value === 'boolean') return value ? 'Si' : 'No';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    return String(value);
};

const initials = (name) => (name || '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();
</script>

<template>
    <Head :title="`Log #${log.id}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Log de Auditoria #{{ log.id }}
            </h2>
        </template>

        <div class="max-w-4xl">
            <div class="mb-6">
                <Link :href="route('audit-logs.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a logs
                </Link>
            </div>

            <div class="space-y-6">
                <!-- Who did what: the headline of the whole page -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-pink-100 text-sm font-semibold text-pink-700">
                            {{ initials(log.actor_label) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-lg font-semibold text-gray-900">{{ log.actor_label }}</span>
                                <span v-if="log.actor_role" class="px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-700">
                                    {{ log.actor_role }}
                                </span>
                                <span :class="[actionColors[log.action] || 'bg-gray-100 text-gray-800', 'px-2 py-0.5 text-xs rounded-full font-medium']">
                                    {{ actionLabels[log.action] || log.action }}
                                </span>
                            </div>
                            <p class="mt-2 text-gray-800">{{ log.summary }}</p>
                            <p class="mt-2 text-sm text-gray-500">
                                {{ formatDateTime(log.created_at) }} &middot; {{ log.context_label }}
                                <span v-if="log.ip_address"> &middot; IP {{ log.ip_address }}</span>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Context -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Modulo</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ moduleLabels[log.module] || log.module }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Tipo de registro</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ log.entity_label }}
                                <span v-if="log.auditable_id" class="text-gray-500">#{{ log.auditable_id }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Registro afectado</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ log.subject_label || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Empleado afectado</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                <span v-if="log.employee">
                                    {{ log.employee.employee_number }} - {{ log.employee.full_name }}
                                </span>
                                <span v-else class="text-gray-400">-</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Cuenta de usuario</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ log.user?.name || 'Sin cuenta asociada' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Origen</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ log.context_label }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Changes as a readable before/after diff -->
                <div v-if="changes.length" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Cambios</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="py-2 pr-4 text-left font-medium text-gray-500">Campo</th>
                                    <th class="py-2 pr-4 text-left font-medium text-gray-500">Antes</th>
                                    <th class="py-2 text-left font-medium text-gray-500">Despues</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="change in changes" :key="change.field" class="border-b border-gray-100 last:border-0">
                                    <td class="py-2 pr-4 font-medium text-gray-700 align-top">{{ change.label }}</td>
                                    <td class="py-2 pr-4 align-top">
                                        <span class="inline-block rounded bg-red-50 px-2 py-1 text-red-700 whitespace-pre-wrap">
                                            {{ formatValue(change.old) }}
                                        </span>
                                    </td>
                                    <td class="py-2 align-top">
                                        <span class="inline-block rounded bg-green-50 px-2 py-1 text-green-700 whitespace-pre-wrap">
                                            {{ formatValue(change.new) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Extra context recorded with the event -->
                <div v-if="metadata.length" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Detalles del evento</h3>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div v-for="row in metadata" :key="row.label">
                            <dt class="text-sm font-medium text-gray-500">{{ row.label }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ formatValue(row.value) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Full history of the same record -->
                <div v-if="related.length" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Historial de este registro</h3>
                    <ul class="divide-y divide-gray-100">
                        <li v-for="entry in related" :key="entry.id" class="py-3 flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span :class="[actionColors[entry.action] || 'bg-gray-100 text-gray-800', 'px-2 py-0.5 text-xs rounded-full font-medium']">
                                        {{ actionLabels[entry.action] || entry.action }}
                                    </span>
                                    <span class="text-sm font-medium text-gray-900">{{ entry.actor_label }}</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-600">{{ entry.summary }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-xs text-gray-500">{{ formatDateTime(entry.created_at) }}</div>
                                <Link :href="route('audit-logs.show', entry.id)" class="text-xs text-pink-600 hover:text-pink-800">
                                    Ver
                                </Link>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Technical Details -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Detalles Tecnicos</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Direccion IP</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ log.ip_address || '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">User Agent</dt>
                            <dd class="mt-1 text-xs text-gray-600 font-mono break-all">{{ log.user_agent || '-' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
