<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    log: Object,
});

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
    login: 'Iniciar sesion',
    logout: 'Cerrar sesion',
    sync: 'Sincronizar',
    export: 'Exportar',
};

const formatValue = (value) => {
    if (value === null || value === undefined) return '-';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    return String(value);
};
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
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('audit-logs.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a logs
                </Link>
            </div>

            <div class="space-y-6">
                <!-- Basic Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Fecha y Hora</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ new Date(log.created_at).toLocaleString('es-MX') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Usuario</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ log.user?.name || 'Sistema' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Modulo</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ moduleLabels[log.module] || log.module }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Accion</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ actionLabels[log.action] || log.action }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Entidad</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ log.auditable_type?.split('\\').pop() || '-' }}
                                <span v-if="log.auditable_id">#{{ log.auditable_id }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Direccion IP</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ log.ip_address || '-' }}
                            </dd>
                        </div>
                    </dl>
                    <div v-if="log.description" class="mt-4">
                        <dt class="text-sm font-medium text-gray-500">Descripcion</dt>
                        <dd class="mt-1 text-sm text-gray-900">
                            {{ log.description }}
                        </dd>
                    </div>
                </div>

                <!-- Changes -->
                <div v-if="log.old_values || log.new_values" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Cambios</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Old Values -->
                        <div v-if="log.old_values">
                            <h4 class="text-sm font-medium text-gray-500 mb-2">Valores Anteriores</h4>
                            <div class="bg-red-50 rounded-lg p-4 overflow-auto max-h-96">
                                <table class="min-w-full text-sm">
                                    <tbody>
                                        <tr v-for="(value, key) in log.old_values" :key="key" class="border-b border-red-100 last:border-0">
                                            <td class="py-2 pr-4 font-medium text-gray-700">{{ key }}</td>
                                            <td class="py-2 text-red-700 whitespace-pre-wrap">{{ formatValue(value) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- New Values -->
                        <div v-if="log.new_values">
                            <h4 class="text-sm font-medium text-gray-500 mb-2">Valores Nuevos</h4>
                            <div class="bg-green-50 rounded-lg p-4 overflow-auto max-h-96">
                                <table class="min-w-full text-sm">
                                    <tbody>
                                        <tr v-for="(value, key) in log.new_values" :key="key" class="border-b border-green-100 last:border-0">
                                            <td class="py-2 pr-4 font-medium text-gray-700">{{ key }}</td>
                                            <td class="py-2 text-green-700 whitespace-pre-wrap">{{ formatValue(value) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Technical Details -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Detalles Tecnicos</h3>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">User Agent</dt>
                            <dd class="mt-1 text-xs text-gray-600 font-mono break-all">
                                {{ log.user_agent || '-' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
