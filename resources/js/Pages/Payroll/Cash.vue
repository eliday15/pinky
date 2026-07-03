<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    period: Object,
    payouts: Array,
    transfers: { type: Array, default: () => [] },
    cashStale: { type: Boolean, default: false },
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

// El pago en efectivo son 2 pasos: (1) preparar la entrega — definir con qué
// billetes/monedas se va a entregar el dinero — y (2) cobrar con la contraseña
// de cada empleado. No se puede cobrar sin confirmar antes la entrega (paso 1).
const deliveryConfirmed = computed(() => !!props.period.cash_delivery_confirmed_at);
const step = ref(deliveryConfirmed.value ? 2 : 1);

const confirmDeliveryForm = useForm({});
const confirmDelivery = () => {
    confirmDeliveryForm.post(route('payroll.confirmDelivery', props.period.id), {
        preserveScroll: true,
        onSuccess: () => { step.value = 2; },
    });
};

// --- Denominaciones disponibles (flexible) ---
// Muchas veces no hay billetes de cierta denominación (p. ej. $1000). El cajero
// puede desmarcar las que no tenga y el desglose (global + por empleado) se
// recalcula al vuelo con greedy sobre las denominaciones habilitadas. La
// elección se recuerda en el navegador (localStorage).
const STORAGE_KEY = 'cash_enabled_denominations';

const loadEnabled = () => {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY));
        if (Array.isArray(saved) && saved.length) {
            const allowed = new Set(props.denominations.map(Number));
            const picked = saved.map(Number).filter((d) => allowed.has(d));
            if (picked.length) return new Set(picked);
        }
    } catch (e) { /* ignore */ }
    return new Set(props.denominations.map(Number));
};

const enabledDenoms = ref(loadEnabled());

const isEnabled = (d) => enabledDenoms.value.has(Number(d));

const toggleDenom = (d) => {
    const n = Number(d);
    const next = new Set(enabledDenoms.value);
    next.has(n) ? next.delete(n) : next.add(n);
    enabledDenoms.value = next;
};

watch(enabledDenoms, (v) => {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify([...v])); } catch (e) { /* ignore */ }
});

// Habilitadas, de mayor a menor (orden del greedy).
const activeDenoms = computed(() =>
    props.denominations.map(Number).filter((d) => enabledDenoms.value.has(d)).sort((a, b) => b - a)
);

// Greedy sobre las denominaciones habilitadas. Devuelve el desglose y el
// remanente no representable (si faltan denominaciones chicas).
const greedy = (amount) => {
    let rem = Math.round(Number(amount) || 0);
    const breakdown = {};
    for (const d of activeDenoms.value) {
        if (rem <= 0) break;
        const c = Math.floor(rem / d);
        if (c > 0) { breakdown[d] = c; rem -= c * d; }
    }
    return { breakdown, leftover: rem };
};

// Filas ordenadas [{denom,count}] del desglose de un monto.
const breakdownRows = (amount) => {
    const { breakdown } = greedy(amount);
    return activeDenoms.value
        .map((denom) => ({ denom, count: breakdown[denom] ?? 0 }))
        .filter((row) => row.count > 0);
};
const leftoverOf = (amount) => greedy(amount).leftover;

// Saldo aún por cobrar de un cobro = total a cobrar menos lo ya cobrado. En un
// cobro normal amount_paid es 0, así que es igual al total; en un pago parcial
// (el recálculo subió el monto después de cobrar) es solo la diferencia.
const collectable = (p) => Math.max(0, Number(p.total_due) - Number(p.amount_paid || 0));

// Solo los cobros en efectivo con monto > 0 (los $0 de quien cobra base por
// transferencia y sin extras no se listan ni se cobran con PIN).
const cashPayouts = computed(() => props.payouts.filter((p) => Number(p.total_due) > 0));

// Lo pendiente de cobro es lo que hay que retirar del banco.
const pendingPayouts = computed(() => cashPayouts.value.filter((p) => p.status !== 'paid'));

// Global = suma de los desgloses individuales (cada empleado recibe billetes
// exactos, no se comparten piezas) sobre el saldo pendiente.
const globalCalc = computed(() => {
    const totals = {};
    let leftover = 0;
    for (const p of pendingPayouts.value) {
        const { breakdown, leftover: lo } = greedy(collectable(p));
        for (const [d, c] of Object.entries(breakdown)) totals[d] = (totals[d] ?? 0) + c;
        leftover += lo;
    }
    return { totals, leftover };
});

const globalRows = computed(() =>
    activeDenoms.value
        .map((denom) => ({ denom, count: globalCalc.value.totals[denom] ?? 0 }))
        .filter((row) => row.count > 0)
);
const globalPieces = computed(() => globalRows.value.reduce((s, r) => s + r.count, 0));
const globalAmount = computed(() => globalRows.value.reduce((s, r) => s + r.denom * r.count, 0));

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

            <!-- La nómina se recalculó después de cerrar el efectivo: los billetes
                 de abajo están viejos hasta aprobar y volver a cerrar. -->
            <div v-if="cashStale" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">
                    &#9888; Los montos de abajo están desactualizados
                </p>
                <p class="text-sm text-amber-700 mt-1">
                    La nómina se recalculó después de preparar el efectivo. Vuelve a la nómina,
                    <span class="font-medium">apruébala</span> y presiona
                    <span class="font-medium">&laquo;Cerrar y preparar efectivo&raquo;</span> otra vez para regenerar los billetes con los montos correctos.
                </p>
                <Link :href="route('payroll.show', period.id)" class="inline-block mt-2 text-sm font-medium text-amber-800 underline">
                    Ir a la nómina &rarr;
                </Link>
            </div>

            <!-- Resumen global: transferencia + efectivo + total -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Transferencia (banco)</p>
                    <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(summary.total_transfer) }}</p>
                    <p class="text-xs text-gray-400">{{ summary.transfer_count }} empleado(s)</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Efectivo</p>
                    <p class="text-2xl font-bold text-pink-600">{{ formatCurrency(summary.total_cash) }}</p>
                    <p class="text-xs text-gray-400">
                        Cobrado {{ formatCurrency(summary.total_paid) }} &middot; Pendiente {{ formatCurrency(summary.total_pending) }}
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Total nomina</p>
                    <p class="text-2xl font-bold text-gray-800">{{ formatCurrency(summary.total_global) }}</p>
                </div>
            </div>

            <!-- Pasos: (1) preparar la entrega / definir billetes  (2) cobrar -->
            <div class="flex gap-2 mb-6">
                <button
                    type="button"
                    @click="step = 1"
                    class="flex-1 px-4 py-3 rounded-lg border text-sm font-medium transition-colors"
                    :class="step === 1 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                >
                    Paso 1 &middot; Preparar entrega (billetes)
                </button>
                <button
                    type="button"
                    :disabled="!deliveryConfirmed"
                    :title="deliveryConfirmed ? '' : 'Primero confirma la preparación del efectivo (Paso 1)'"
                    @click="step = 2"
                    class="flex-1 px-4 py-3 rounded-lg border text-sm font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="step === 2 ? 'bg-pink-600 text-white border-pink-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                >
                    Paso 2 &middot; Cobrar
                    <span v-if="!deliveryConfirmed">&#128274;</span>
                </button>
            </div>

            <!-- PASO 1: definir cómo se entrega el dinero (transferencias + billetes) -->
            <div v-show="step === 1">

            <!-- Transferencias (banco/CONTPAQi) -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-1">Transferencias (banco)</h2>
                <p class="text-xs text-gray-500 mb-4">
                    Sueldo base que se paga por transferencia / CONTPAQi. No requiere contraseña de cobro.
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

            <!-- Global denominations -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-1">Efectivo a retirar (global)</h2>
                <p class="text-xs text-gray-500 mb-4">
                    Desglose minimo de billetes y monedas para lo que aun esta pendiente de cobro.
                </p>

                <!-- Denominaciones disponibles: desmarca las que no tengas y el desglose se recalcula -->
                <div class="mb-4">
                    <p class="text-xs font-medium text-gray-600 mb-2">
                        Denominaciones disponibles
                        <span class="font-normal text-gray-400">&mdash; desmarca las que no tengas (ej. $1000)</span>
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="d in denominations"
                            :key="d"
                            class="inline-flex items-center px-2.5 py-1 rounded-full border text-xs cursor-pointer select-none transition-colors"
                            :class="isEnabled(d) ? 'bg-pink-50 border-pink-300 text-pink-700' : 'bg-gray-50 border-gray-200 text-gray-400 line-through'"
                        >
                            <input type="checkbox" :checked="isEnabled(d)" @change="toggleDenom(d)" class="sr-only" />
                            ${{ d }}
                        </label>
                    </div>
                </div>

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
                                <td class="py-2 pr-4">Total ({{ globalPieces }} piezas)</td>
                                <td class="py-2 pr-4"></td>
                                <td class="py-2 pr-4 text-right">{{ formatCurrency(globalAmount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-else-if="!pendingPayouts.length" class="text-sm text-gray-500">No hay efectivo pendiente de retirar.</p>

                <p v-if="globalCalc.leftover > 0" class="mt-3 text-sm text-amber-600">
                    &#9888; Faltan {{ formatCurrency(globalCalc.leftover) }} que no se pueden formar con las denominaciones elegidas. Habilita una denominacion mas chica (p. ej. $1).
                </p>
            </div>

            <div class="flex items-center justify-between gap-3 flex-wrap">
                <p v-if="!deliveryConfirmed" class="text-sm text-gray-500">
                    Revisa el desglose de billetes y confirma que preparaste el efectivo para habilitar el cobro.
                </p>
                <p v-else class="text-sm font-medium text-green-600">
                    &#10003; Entrega del efectivo confirmada.
                </p>
                <button
                    v-if="!deliveryConfirmed && can?.payCash"
                    type="button"
                    :disabled="confirmDeliveryForm.processing"
                    @click="confirmDelivery"
                    class="px-5 py-2.5 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm font-medium disabled:opacity-50"
                >
                    Confirmar entrega y continuar &rarr;
                </button>
                <button
                    v-else
                    type="button"
                    @click="step = 2"
                    class="px-5 py-2.5 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm font-medium"
                >
                    Continuar a cobrar &rarr;
                </button>
            </div>
            </div>
            <!-- /PASO 1 -->

            <!-- PASO 2: cobrar con la contraseña de cada empleado -->
            <div v-show="step === 2">

            <!-- Efectivo por empleado -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-lg font-semibold text-gray-800">Efectivo por empleado</h2>
                    <p class="text-xs text-gray-500">Cobro con la contraseña del empleado. Solo aparece quien recibe efectivo.</p>
                </div>
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
                        <tr v-for="payout in cashPayouts" :key="payout.id">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ payout.employee_name }}</div>
                                <div class="text-xs text-gray-400">{{ payout.employee_number }}</div>
                            </td>
                            <td class="px-4 py-3 text-right">{{ formatCurrency(payout.period_amount) }}</td>
                            <td class="px-4 py-3 text-right" :class="payout.opening_balance > 0 ? 'text-amber-600' : 'text-gray-400'">
                                {{ payout.opening_balance > 0 ? formatCurrency(payout.opening_balance) : '-' }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-800">
                                {{ formatCurrency(collectable(payout)) }}
                                <div v-if="payout.amount_paid > 0" class="text-xs font-normal text-gray-400">
                                    ya cobró {{ formatCurrency(payout.amount_paid) }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs text-gray-500">
                                    <template v-for="(row, i) in breakdownRows(collectable(payout))" :key="row.denom">
                                        <span>{{ row.count }}&times;${{ row.denom }}</span><span v-if="i < breakdownRows(collectable(payout)).length - 1">, </span>
                                    </template>
                                    <span v-if="!breakdownRows(collectable(payout)).length && leftoverOf(collectable(payout)) <= 0">-</span>
                                    <span v-if="leftoverOf(collectable(payout)) > 0" class="text-amber-600"> (falta {{ formatCurrency(leftoverOf(collectable(payout))) }})</span>
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
                                    :disabled="!payout.has_cash_pin || !deliveryConfirmed"
                                    :title="!deliveryConfirmed ? 'Primero confirma la preparación del efectivo (Paso 1)' : (payout.has_cash_pin ? '' : 'El empleado no tiene contraseña de cobro configurada')"
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
                        <tr v-if="!cashPayouts.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">
                                No hay cobros en efectivo para este periodo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </div>
            <!-- /PASO 2 -->
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
                            <span class="font-medium text-gray-800">{{ formatCurrency(collectable(activePayout)) }}</span>
                            <span v-if="activePayout.amount_paid > 0" class="text-xs text-gray-400">
                                (ya cobró {{ formatCurrency(activePayout.amount_paid) }})
                            </span>
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
