<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    period: Object,
    transfers: { type: Array, default: () => [] },
    summary: Object,
});

const formatCurrency = (amount) => new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
}).format(amount || 0);
</script>

<template>
    <Head :title="`Transferencias: ${period.name}`" />

    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- Header -->
            <div class="mb-6">
                <Link :href="route('payroll.show', period.id)" class="text-pink-600 hover:text-pink-800 text-sm">
                    &larr; Volver a la nomina
                </Link>
                <h1 class="text-2xl font-bold text-gray-800 mt-2">Transferencias (banco)</h1>
                <p class="text-gray-500">{{ period.name }}</p>
            </div>

            <!-- Resumen -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow border-l-4 border-indigo-500 p-5">
                    <p class="text-sm text-gray-500">Total a transferir</p>
                    <p class="text-3xl font-bold text-indigo-600">{{ formatCurrency(summary.total_transfer) }}</p>
                    <p class="text-xs text-gray-400 mt-1">{{ summary.transfer_count }} empleado(s)</p>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-xs text-gray-500 mb-4">
                    Sueldo base que se paga por transferencia / CONTPAQi (empleados en banco / IMSS).
                    No requiere contraseña de cobro.
                </p>
                <div v-if="transfers.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2 pr-4">Empleado</th>
                                <th class="py-2 pr-4 text-right">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(t, i) in transfers" :key="i" class="border-b last:border-0">
                                <td class="py-2 pr-4">
                                    <span class="text-gray-800">{{ t.employee_name }}</span>
                                    <span class="text-xs text-gray-400 ml-2">{{ t.employee_number }}</span>
                                </td>
                                <td class="py-2 pr-4 text-right font-medium">{{ formatCurrency(t.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold text-gray-800 border-t">
                                <td class="py-2 pr-4">Total transferencias</td>
                                <td class="py-2 pr-4 text-right">{{ formatCurrency(summary.total_transfer) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="text-sm text-gray-500">No hay pagos por transferencia en este periodo.</p>
            </div>
        </div>
    </AppLayout>
</template>
