<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    periods: Array,
});

const formatCurrency = (amount) => new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
}).format(amount || 0);

const formatDate = (value) => (value
    ? new Date(`${value}T00:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' })
    : '');

// Estado del ciclo de efectivo de un periodo, en orden del flujo:
// entrega pendiente → por cobrar → cerrada (efectivo por devolver) → devuelta.
const statusOf = (p) => {
    if (p.return_received) return { label: 'Devuelta', class: 'bg-green-100 text-green-800' };
    if (p.collection_closed) return { label: 'Cerrada', class: 'bg-gray-200 text-gray-700' };
    if (p.delivery_confirmed) return { label: 'Por cobrar', class: 'bg-pink-100 text-pink-800' };
    return { label: 'Entrega pendiente', class: 'bg-blue-100 text-blue-800' };
};
</script>

<template>
    <Head title="Cobro de efectivo" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Cobro de efectivo</h1>
                <p class="text-gray-500">Nóminas con efectivo preparado para cobrar.</p>
            </div>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th class="px-4 py-3">Nómina</th>
                                <th class="px-4 py-3">Semana</th>
                                <th class="px-4 py-3 text-center">Estado</th>
                                <th class="px-4 py-3 text-right">Pendiente de cobro</th>
                                <th class="px-4 py-3 text-right">Efectivo a regresar</th>
                                <th class="px-4 py-3 text-right">Accion</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in periods" :key="p.id">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-800">{{ p.name }}</div>
                                    <div v-if="p.department_name" class="text-xs text-gray-400">{{ p.department_name }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{ formatDate(p.start_date) }} &ndash; {{ formatDate(p.end_date) }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="px-2 py-1 rounded-full text-xs font-medium" :class="statusOf(p).class">
                                        {{ statusOf(p).label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right" :class="p.total_pending > 0 ? 'text-amber-600 font-medium' : 'text-gray-400'">
                                    {{ p.total_pending > 0 ? formatCurrency(p.total_pending) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right" :class="p.return_amount > 0 && !p.return_received ? 'text-pink-700 font-semibold' : 'text-gray-400'">
                                    {{ p.return_amount !== null && p.collection_closed ? formatCurrency(p.return_amount) : '-' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Link
                                        :href="route('payroll.cash', p.id)"
                                        class="px-3 py-1.5 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-xs font-medium"
                                    >
                                        {{ p.collection_closed ? 'Ver' : 'Cobrar' }}
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!periods.length">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                                    No hay nóminas con efectivo preparado.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
