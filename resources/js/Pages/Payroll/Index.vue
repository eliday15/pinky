<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    periods: Object,
});

const statusColors = {
    draft: 'bg-gray-100 text-gray-800',
    calculating: 'bg-blue-100 text-blue-800',
    review: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    paid: 'bg-purple-100 text-purple-800',
};

const statusLabels = {
    draft: 'Borrador',
    calculating: 'Calculando',
    review: 'En Revision',
    approved: 'Aprobada',
    paid: 'Pagada',
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount || 0);
};

const deletePeriod = (period) => {
    if (confirm('¿Eliminar este periodo de nomina?')) {
        router.delete(route('payroll.destroy', period.id));
    }
};
</script>

<template>
    <Head title="Nomina" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nomina
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Periodos de Nomina</h1>
                <p class="text-gray-600">Gestiona los periodos de pago y calcula nominas</p>
            </div>
            <Link
                :href="route('payroll.create')"
                class="inline-flex items-center px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
            >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Nuevo Periodo
            </Link>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Periodo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Pago</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleados</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Neto</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="period in periods.data" :key="period.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ period.name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ formatDate(period.start_date) }} - {{ formatDate(period.end_date) }}
                                </p>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">
                            {{ period.type === 'biweekly' ? 'Quincenal' : period.type === 'weekly' ? 'Semanal' : 'Mensual' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ formatDate(period.payment_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                            {{ period.entries_count || 0 }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                            {{ formatCurrency(period.entries_sum_net_pay) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[statusColors[period.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ statusLabels[period.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm space-x-2">
                            <Link
                                :href="route('payroll.show', period.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Ver
                            </Link>
                            <button
                                v-if="period.status === 'draft'"
                                @click="deletePeriod(period)"
                                class="text-red-600 hover:text-red-900"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="periods.data.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No hay periodos de nomina registrados
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="periods.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ periods.from }} a {{ periods.to }} de {{ periods.total }}
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in periods.links"
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
