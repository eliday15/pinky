<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: Array,
});

const statusColors = {
    pending: 'bg-gray-100 text-gray-800 border-gray-300',
    running: 'bg-blue-100 text-blue-800 border-blue-300',
    completed: 'bg-green-100 text-green-800 border-green-300',
    failed: 'bg-red-100 text-red-800 border-red-300',
};

const statusLabels = {
    pending: 'Pendiente',
    running: 'En Proceso',
    completed: 'Completado',
    failed: 'Fallido',
};

const expandedLog = ref(null);

const toggleExpand = (logId) => {
    expandedLog.value = expandedLog.value === logId ? null : logId;
};

const formatDateTime = (datetime) => {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString('es-MX');
};

/**
 * Calculate duration between two datetime strings.
 *
 * Args:
 *     startedAt: ISO datetime string for start
 *     completedAt: ISO datetime string for end
 *
 * Returns:
 *     Human-readable duration string
 */
const getDuration = (startedAt, completedAt) => {
    if (!startedAt || !completedAt) return '-';

    const start = new Date(startedAt);
    const end = new Date(completedAt);
    const diffMs = end - start;

    if (diffMs < 0) return '-';

    const seconds = Math.floor(diffMs / 1000);
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    if (minutes > 0) {
        return `${minutes}m ${remainingSeconds}s`;
    }
    return `${seconds}s`;
};
</script>

<template>
    <Head title="Logs de Sincronizacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Logs de Sincronizacion
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6">
            <Link :href="route('attendance.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a asistencia
            </Link>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Inicio</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duracion</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Procesados</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Creados</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actualizados</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Errores</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Iniciado por</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Detalle</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <template v-for="log in logs" :key="log.id">
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                #{{ log.id }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ formatDateTime(log.started_at) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ getDuration(log.started_at, log.completed_at) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="[statusColors[log.status], 'px-2 py-1 text-xs font-medium rounded-full border']">
                                    {{ statusLabels[log.status] || log.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ log.records_processed ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ log.records_created ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ log.records_updated ?? '-' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    v-if="log.errors_count > 0"
                                    class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800"
                                >
                                    {{ log.errors_count }}
                                </span>
                                <span v-else class="text-sm text-gray-400">0</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ log.triggered_by?.name || 'Sistema' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <button
                                    v-if="log.error_details"
                                    @click="toggleExpand(log.id)"
                                    class="text-pink-600 hover:text-pink-800"
                                >
                                    {{ expandedLog === log.id ? 'Ocultar' : 'Ver errores' }}
                                </button>
                                <span v-else class="text-gray-400">-</span>
                            </td>
                        </tr>
                        <!-- Expanded Error Details Row -->
                        <tr v-if="expandedLog === log.id && log.error_details">
                            <td colspan="10" class="px-6 py-4 bg-red-50">
                                <div class="text-sm">
                                    <h4 class="font-medium text-red-800 mb-2">Detalle de Errores</h4>
                                    <pre class="text-xs text-red-700 bg-red-100 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap">{{ typeof log.error_details === 'string' ? log.error_details : JSON.stringify(log.error_details, null, 2) }}</pre>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="logs.length === 0">
                        <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                            No hay logs de sincronizacion
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
