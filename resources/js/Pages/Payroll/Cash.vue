<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    period: Object,
    payouts: Array,
    globalBreakdown: Object,
    denominations: Array,
    summary: Object,
    can: Object,
});

const formatCurrency = (amount) => new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
}).format(amount || 0);

const formatPieces = (denom) => (denom >= 20 ? `Billete $${denom}` : `Moneda $${denom}`);

// Ordered [{ denom, count }] for a denom=>count map, using the canonical
// high-to-low denomination list (JSON object key order is not guaranteed).
const orderedBreakdown = (breakdown) => {
    const map = breakdown || {};
    return props.denominations
        .map((denom) => ({ denom, count: map[denom] ?? map[String(denom)] ?? 0 }))
        .filter((row) => row.count > 0);
};

const globalRows = computed(() => orderedBreakdown(props.globalBreakdown?.denominations));

// --- Collection modal ---
const showCollect = ref(false);
const activePayout = ref(null);
const form = useForm({ pin: '' });

const openCollect = (payout) => {
    activePayout.value = payout;
    form.reset();
    form.clearErrors();
    showCollect.value = true;
};

const closeCollect = () => {
    showCollect.value = false;
    activePayout.value = null;
    form.reset();
    form.clearErrors();
};

const submitCollect = () => {
    if (!activePayout.value) return;
    form.post(route('payroll.payouts.collect', [props.period.id, activePayout.value.id]), {
        preserveScroll: true,
        onSuccess: () => closeCollect(),
    });
};
</script>

<template>
    <Head :title="`Pago en efectivo: ${period.name}`" />

    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- Header -->
            <div class="mb-6">
                <Link :href="route('payroll.show', period.id)" class="text-pink-600 hover:text-pink-800 text-sm">
                    &larr; Volver a la nomina
                </Link>
                <h1 class="text-2xl font-bold text-gray-800 mt-2">Pago en efectivo</h1>
                <p class="text-gray-500">{{ period.name }}</p>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Total a pagar</p>
                    <p class="text-2xl font-bold text-gray-800">{{ formatCurrency(summary.total_due) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Cobrado ({{ summary.paid_count }})</p>
                    <p class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.total_paid) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Pendiente ({{ summary.pending_count }})</p>
                    <p class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.total_pending) }}</p>
                </div>
            </div>

            <!-- Global denominations -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-1">Efectivo a retirar (global)</h2>
                <p class="text-xs text-gray-500 mb-4">
                    Desglose minimo de billetes y monedas para lo que aun esta pendiente de cobro.
                </p>
                <div v-if="globalRows.length" class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2 pr-4">Denominacion</th>
                                <th class="py-2 pr-4 text-right">Cantidad</th>
                                <th class="py-2 pr-4 text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in globalRows" :key="row.denom" class="border-b last:border-0">
                                <td class="py-2 pr-4">{{ formatPieces(row.denom) }}</td>
                                <td class="py-2 pr-4 text-right font-medium">{{ row.count }}</td>
                                <td class="py-2 pr-4 text-right">{{ formatCurrency(row.denom * row.count) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold text-gray-800">
                                <td class="py-2 pr-4">Total ({{ globalBreakdown.total_pieces }} piezas)</td>
                                <td class="py-2 pr-4"></td>
                                <td class="py-2 pr-4 text-right">{{ formatCurrency(globalBreakdown.total_amount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else class="text-sm text-gray-500">No hay efectivo pendiente de retirar.</p>
            </div>

            <!-- Per-employee table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th class="px-4 py-3">Empleado</th>
                            <th class="px-4 py-3 text-right">Del periodo</th>
                            <th class="px-4 py-3 text-right">Acumulado</th>
                            <th class="px-4 py-3 text-right">Total a cobrar</th>
                            <th class="px-4 py-3">Billetes</th>
                            <th class="px-4 py-3 text-center">Estado</th>
                            <th class="px-4 py-3 text-right">Accion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="payout in payouts" :key="payout.id">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ payout.employee_name }}</div>
                                <div class="text-xs text-gray-400">{{ payout.employee_number }}</div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ formatCurrency(payout.period_amount) }}</td>
                            <td class="px-4 py-3 text-right" :class="payout.opening_balance > 0 ? 'text-amber-600' : 'text-gray-400'">
                                {{ payout.opening_balance > 0 ? formatCurrency(payout.opening_balance) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800">{{ formatCurrency(payout.total_due) }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-500">
                                    <template v-for="(row, i) in orderedBreakdown(payout.denomination_breakdown)" :key="row.denom">
                                        <span>{{ row.count }}&times;${{ row.denom }}</span><span v-if="i < orderedBreakdown(payout.denomination_breakdown).length - 1">, </span>
                                    </template>
                                    <span v-if="!orderedBreakdown(payout.denomination_breakdown).length">-</span>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span
                                    class="px-2 py-1 rounded-full text-xs font-medium"
                                    :class="payout.status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                                >
                                    {{ payout.status === 'paid' ? 'Cobrado' : 'Pendiente' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button
                                    v-if="can?.payCash && payout.status !== 'paid'"
                                    :disabled="!payout.has_cash_pin"
                                    :title="payout.has_cash_pin ? '' : 'El empleado no tiene contraseña de cobro configurada'"
                                    @click="openCollect(payout)"
                                    class="px-3 py-1.5 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    Cobrar
                                </button>
                                <span v-else-if="payout.status === 'paid'" class="text-xs text-gray-400">
                                    {{ payout.collected_at ? new Date(payout.collected_at).toLocaleDateString('es-MX') : '' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!payouts.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">
                                No hay cobros preparados para este periodo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Collect modal -->
        <div v-if="showCollect" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="closeCollect"></div>

                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Cobrar efectivo</h3>
                        <p class="text-sm text-gray-500 mt-1" v-if="activePayout">
                            {{ activePayout.employee_name }} &mdash;
                            <span class="font-medium text-gray-800">{{ formatCurrency(activePayout.total_due) }}</span>
                        </p>
                    </div>

                    <form @submit.prevent="submitCollect">
                        <div class="px-6 py-4">
                            <p class="text-sm text-gray-600 mb-4">
                                El empleado ingresa su contraseña de cobro para confirmar que recibio su efectivo.
                            </p>
                            <label for="cash_pin" class="block text-sm font-medium text-gray-700 mb-1">Contraseña de cobro</label>
                            <input
                                id="cash_pin"
                                v-model="form.pin"
                                type="password"
                                autocomplete="off"
                                autofocus
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.pin }"
                            />
                            <p v-if="form.errors.pin" class="mt-1 text-sm text-red-600">{{ form.errors.pin }}</p>
                        </div>

                        <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                            <button type="button" @click="closeCollect" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing || !form.pin"
                                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                            >
                                {{ form.processing ? 'Verificando...' : 'Confirmar cobro' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
