<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, nextTick } from 'vue';

const props = defineProps({
    period: Object,
    payouts: Array,
    cashStale: { type: Boolean, default: false },
    globalBreakdown: Object,
    denominations: Array,
    // Denominaciones habilitadas guardadas en el periodo (null = todas).
    enabledDenominations: { type: Array, default: null },
    summary: Object,
    collection: Object,
    returnBreakdown: Object,
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
// El Paso 1 es exclusivo del custodio (superadmin); el cobrador solo ve el 2.
const deliveryConfirmed = computed(() => !!props.period.cash_delivery_confirmed_at);
const step = ref(deliveryConfirmed.value || !props.can?.deliverCash ? 2 : 1);

const confirmDeliveryForm = useForm({});
const confirmDelivery = () => {
    confirmDeliveryForm.post(route('payroll.confirmDelivery', props.period.id), {
        preserveScroll: true,
        onSuccess: () => { step.value = 2; },
    });
};

// --- Cierre del cobro y devolución del sobrante ---
// Al terminar de cobrar, el cobrador cierra la nómina: se congela cuánto
// efectivo debe regresar (lo no cobrado) y ya no se puede cobrar aquí; el
// saldo de cada empleado se acumula a la siguiente semana. El superadmin
// después confirma que recibió el efectivo devuelto (o reabre si fue error).
const collectionClosed = computed(() => !!props.collection?.closed_at);
const returnReceived = computed(() => !!props.collection?.received_at);
const returnAmount = computed(() => Number(props.collection?.return_amount ?? 0));

// Filas [{denom,count}] del snapshot de billetes a regresar, de mayor a menor.
const returnRows = computed(() =>
    Object.entries(props.returnBreakdown || {})
        .map(([denom, count]) => ({ denom: Number(denom), count: Number(count) }))
        .filter((r) => r.count > 0)
        .sort((a, b) => b.denom - a.denom)
);

const formatDateTime = (value) => (value
    ? new Date(value).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
    : '');

const showCloseCollection = ref(false);
const closeCollectionForm = useForm({});
const submitCloseCollection = () => {
    closeCollectionForm.post(route('payroll.closeCollection', props.period.id), {
        preserveScroll: true,
        onSuccess: () => { showCloseCollection.value = false; },
    });
};

const receiveReturnForm = useForm({});
const submitReceiveReturn = () => {
    receiveReturnForm.post(route('payroll.receiveReturn', props.period.id), { preserveScroll: true });
};

const reopenForm = useForm({});
const submitReopen = () => {
    reopenForm.post(route('payroll.reopenCollection', props.period.id), { preserveScroll: true });
};

// --- Denominaciones disponibles (flexible) ---
// Muchas veces no hay billetes de cierta denominación (p. ej. $1000). El
// custodio desmarca las que no tenga y el desglose (global + por empleado) se
// recalcula al vuelo con greedy sobre las denominaciones habilitadas. La
// elección se guarda POR PERIODO en el servidor: antes vivía en el localStorage
// del custodio, así que el cobrador —en otra máquina— seguía viendo billetes de
// $1000 (Luis 2026-07-16). El servidor es la fuente de verdad y ambos pasos
// muestran el mismo desglose.
const loadEnabled = () => {
    const allowed = new Set(props.denominations.map(Number));
    const saved = Array.isArray(props.enabledDenominations)
        ? props.enabledDenominations.map(Number).filter((d) => allowed.has(d))
        : [];
    // null / vacío = todas habilitadas (comportamiento por defecto).
    return saved.length ? new Set(saved) : new Set(props.denominations.map(Number));
};

const enabledDenoms = ref(loadEnabled());

const isEnabled = (d) => enabledDenoms.value.has(Number(d));

// Sólo el custodio (Paso 1) puede cambiar las denominaciones; el cobrador las ve.
const canEditDenoms = computed(() => !!props.can?.deliverCash);

const persistDenoms = () => {
    router.post(
        route('payroll.cashDenominations', props.period.id),
        { denominations: [...enabledDenoms.value] },
        { preserveScroll: true, preserveState: true, only: ['enabledDenominations'] },
    );
};

const toggleDenom = (d) => {
    if (!canEditDenoms.value) return;
    const n = Number(d);
    const next = new Set(enabledDenoms.value);
    next.has(n) ? next.delete(n) : next.add(n);
    // Nunca dejar la lista vacía: al menos una denominación debe quedar.
    if (next.size === 0) return;
    enabledDenoms.value = next;
    persistDenoms();
};

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

// --- Buscador de empleado (Paso 2) ---
// La lista puede tener decenas de empleados; el cobrador escribe el nombre y la
// tabla se filtra al vuelo. Se ignoran acentos y mayúsculas (Luis 2026-07-16).
const search = ref('');
const normalize = (s) => (s ?? '')
    .toString()
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

const filteredCashPayouts = computed(() => {
    const q = normalize(search.value).trim();
    if (!q) return cashPayouts.value;
    return cashPayouts.value.filter((p) =>
        normalize(p.employee_name).includes(q) || normalize(p.employee_number).includes(q));
});

// Nombres para el autocomplete nativo (<datalist>).
const employeeNameOptions = computed(() =>
    cashPayouts.value.map((p) => p.employee_name).filter(Boolean));

// --- Impresión ---
// Dos hojas print-only distintas (la app se oculta con print:hidden):
// 'bills'     → conteo global de billetes a retirar (Paso 1, para el banco).
// 'envelopes' → desglose por empleado para armar los sobres (Paso 2, cobro).
const printMode = ref('bills');
const printedAt = ref(null);
const printedAtLabel = computed(() => (printedAt.value
    ? printedAt.value.toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
    : ''));

const printCash = (mode) => {
    printMode.value = mode;
    printedAt.value = new Date();
    nextTick(() => window.print());
};

// Desglose "3×$500, 2×$50" de un monto, para la hoja impresa.
const breakdownLabel = (amount) => {
    const parts = breakdownRows(amount).map((r) => `${r.count}×$${r.denom}`);
    const lo = leftoverOf(amount);
    let label = parts.join(', ') || '—';
    if (lo > 0) label += ` (falta ${formatCurrency(lo)})`;
    return label;
};

// Lo pendiente de cobro es lo que hay que retirar del banco.
const pendingPayouts = computed(() => cashPayouts.value.filter((p) => p.status !== 'paid'));

// Total pendiente autoconsistente con la tabla impresa (suma de collectable).
const pendingTotal = computed(() => pendingPayouts.value.reduce((s, p) => s + collectable(p), 0));

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
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 print:hidden">
            <!-- Header: el cobrador no tiene acceso al detalle de la nómina,
                 su regreso es a la lista de cobro de efectivo. -->
            <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <Link v-if="can?.payCash" :href="route('payroll.show', period.id)" class="text-pink-600 hover:text-pink-800 text-sm">
                        &larr; Volver a la nomina
                    </Link>
                    <Link v-else :href="route('payroll.cashCollection')" class="text-pink-600 hover:text-pink-800 text-sm">
                        &larr; Volver a cobro de efectivo
                    </Link>
                    <h1 class="text-2xl font-bold text-gray-800 mt-2">Pago en efectivo</h1>
                    <p class="text-gray-500">{{ period.name }}</p>
                </div>
                <button
                    type="button"
                    @click="printCash('bills')"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm font-medium"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Imprimir billetes
                </button>
            </div>

            <!-- Nómina cerrada por el cobrador: efectivo a regresar y su recepción -->
            <div v-if="collectionClosed" class="mb-6 rounded-lg border p-4" :class="returnReceived ? 'border-green-300 bg-green-50' : 'border-pink-300 bg-pink-50'">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold" :class="returnReceived ? 'text-green-800' : 'text-pink-800'">
                            Nómina cerrada el {{ formatDateTime(collection.closed_at) }}<template v-if="collection.closed_by_name"> por {{ collection.closed_by_name }}</template>
                        </p>
                        <p v-if="returnAmount > 0" class="text-2xl font-bold mt-1" :class="returnReceived ? 'text-green-700' : 'text-pink-700'">
                            Efectivo a regresar: {{ formatCurrency(returnAmount) }}
                        </p>
                        <p v-else class="text-sm mt-1" :class="returnReceived ? 'text-green-700' : 'text-pink-700'">
                            Todos los empleados cobraron: no hay efectivo por regresar.
                        </p>
                        <p v-if="returnRows.length" class="text-xs text-gray-600 mt-2">
                            Billetes a regresar:
                            <template v-for="(row, i) in returnRows" :key="row.denom">
                                <span>{{ row.count }}&times;${{ row.denom }}</span><span v-if="i < returnRows.length - 1">, </span>
                            </template>
                        </p>
                        <p class="text-xs text-gray-500 mt-2">
                            Lo no cobrado se acumula a cada empleado y se paga la siguiente semana.
                        </p>
                        <p v-if="returnReceived" class="text-sm font-medium text-green-700 mt-2">
                            &#10003; Efectivo recibido el {{ formatDateTime(collection.received_at) }}<template v-if="collection.received_by_name"> por {{ collection.received_by_name }}</template>
                        </p>
                    </div>
                    <div v-if="!returnReceived" class="flex flex-col gap-2">
                        <button
                            v-if="can?.receiveReturn"
                            type="button"
                            :disabled="receiveReturnForm.processing"
                            @click="submitReceiveReturn"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium disabled:opacity-50"
                        >
                            Recibir efectivo devuelto{{ returnAmount > 0 ? ` (${formatCurrency(returnAmount)})` : '' }}
                        </button>
                        <button
                            v-if="can?.reopenCollection"
                            type="button"
                            :disabled="reopenForm.processing"
                            @click="submitReopen"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm font-medium disabled:opacity-50"
                        >
                            Reabrir cobro
                        </button>
                        <p v-if="!can?.receiveReturn" class="text-xs text-gray-500 max-w-[14rem]">
                            Entrega el efectivo al super admin; él confirmará la recepción.
                        </p>
                    </div>
                </div>
            </div>

            <!-- El cobrador aún no puede hacer nada: falta que el custodio entregue -->
            <div v-if="!deliveryConfirmed && !can?.deliverCash" class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
                <p class="text-sm text-blue-800">
                    El super admin aún no confirma la entrega del efectivo de este periodo. Podrás cobrar en cuanto la confirme.
                </p>
            </div>

            <!-- La nómina se recalculó después de cerrar el efectivo: los billetes
                 de abajo están viejos hasta aprobar y volver a cerrar. -->
            <div v-if="cashStale && can?.deliverCash" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
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

            <!-- Resumen: solo efectivo (las transferencias tienen su propia pantalla) -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Efectivo total</p>
                    <p class="text-2xl font-bold text-pink-600">{{ formatCurrency(summary.total_cash) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Cobrado</p>
                    <p class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.total_paid) }}</p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <p class="text-sm text-gray-500">Pendiente</p>
                    <p class="text-2xl font-bold text-amber-600">{{ formatCurrency(summary.total_pending) }}</p>
                </div>
            </div>

            <!-- Pasos: (1) preparar la entrega / definir billetes  (2) cobrar.
                 El Paso 1 es exclusivo del custodio (superadmin); el cobrador
                 solo ve el Paso 2. -->
            <div class="flex gap-2 mb-6">
                <button
                    v-if="can?.deliverCash"
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

            <!-- PASO 1: definir con qué billetes/monedas se entrega el efectivo -->
            <div v-show="step === 1">

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
                    v-if="!deliveryConfirmed && can?.deliverCash"
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
                <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Efectivo por empleado</h2>
                        <p class="text-xs text-gray-500">Cobro con la contraseña del empleado. Solo aparece quien recibe efectivo.</p>
                    </div>
                    <button
                        type="button"
                        @click="printCash('envelopes')"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm font-medium"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Imprimir sobres
                    </button>
                </div>

                <!-- Buscador de empleado (autocomplete nativo por nombre) -->
                <div class="px-4 py-3 border-b border-gray-100">
                    <div class="relative max-w-sm">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
                        </svg>
                        <input
                            v-model="search"
                            type="search"
                            list="cash-employee-names"
                            placeholder="Buscar empleado por nombre o número…"
                            class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm focus:border-pink-500 focus:ring-pink-500"
                        />
                        <datalist id="cash-employee-names">
                            <option v-for="name in employeeNameOptions" :key="name" :value="name" />
                        </datalist>
                    </div>
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
                        <tr v-for="payout in filteredCashPayouts" :key="payout.id">
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
                                <template v-if="can?.collectCash && payout.status !== 'paid'">
                                    <button
                                        :disabled="!payout.has_cash_pin || !deliveryConfirmed"
                                        :title="!deliveryConfirmed ? 'Primero confirma la preparación del efectivo (Paso 1)' : (payout.has_cash_pin ? '' : 'El empleado no tiene contraseña de cobro configurada')"
                                        @click="openCollect(payout)"
                                        class="px-3 py-1.5 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                    >
                                        Cobrar
                                    </button>
                                    <!-- Leyenda del porqué el botón está deshabilitado: el
                                         empleado no tiene contraseña de cobro. Solo el admin
                                         (can.payCash) la puede configurar. -->
                                    <div v-if="deliveryConfirmed && !payout.has_cash_pin" class="mt-1 text-xs text-amber-600">
                                        Sin contraseña de cobro
                                        <Link v-if="can?.payCash" :href="route('employees.edit', payout.employee_id)" class="underline hover:text-amber-700">
                                            &middot; Configurar
                                        </Link>
                                    </div>
                                </template>
                                <span v-else-if="payout.status === 'paid'" class="text-xs text-gray-400">
                                    {{ payout.collected_at ? new Date(payout.collected_at).toLocaleDateString('es-MX') : '' }}
                                </span>
                                <span v-else-if="collectionClosed" class="text-xs text-gray-400" title="La nómina se cerró; el saldo se acumula a la siguiente semana">
                                    Se acumula
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!cashPayouts.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">
                                No hay cobros en efectivo para este periodo.
                            </td>
                        </tr>
                        <tr v-else-if="!filteredCashPayouts.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">
                                Ningún empleado coincide con &laquo;{{ search }}&raquo;.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Cerrar nómina: al terminar de cobrar, congela el efectivo a
                 regresar y bloquea nuevos cobros en este periodo. -->
            <div v-if="can?.closeCollection" class="mt-6 bg-white rounded-lg shadow p-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-800">¿Terminaste de cobrar?</p>
                    <p class="text-xs text-gray-500">
                        <template v-if="summary.pending_count > 0">
                            Quedan {{ summary.pending_count }} empleado(s) sin cobrar por {{ formatCurrency(summary.total_pending) }}; ese efectivo se regresa a la empresa y su saldo se acumula a la siguiente semana.
                        </template>
                        <template v-else>
                            Todos cobraron: al cerrar no habrá efectivo por regresar.
                        </template>
                    </p>
                </div>
                <button
                    type="button"
                    @click="showCloseCollection = true"
                    class="px-5 py-2.5 bg-gray-800 text-white rounded-lg hover:bg-gray-900 text-sm font-medium"
                >
                    Cerrar nómina
                </button>
            </div>
            </div>
            <!-- /PASO 2 -->
        </div>

        <!-- Hoja imprimible: conteo de billetes a retirar + desglose por
             empleado para armar los sobres. Solo existe al imprimir
             (hidden print:block); la app entera se oculta con print:hidden. -->
        <section class="hidden print:block bg-white p-2 text-black">
            <div class="flex items-baseline justify-between border-b-2 border-black pb-2 mb-4">
                <h1 class="text-xl font-bold">Pago en efectivo — {{ period.name }}</h1>
                <p class="text-xs">Impreso: {{ printedAtLabel }}</p>
            </div>

            <div class="flex gap-10 mb-5 text-sm">
                <p>Efectivo pendiente de cobro: <span class="font-bold">{{ formatCurrency(pendingTotal) }}</span></p>
                <p>Empleados por cobrar: <span class="font-bold">{{ pendingPayouts.length }}</span></p>
            </div>

            <template v-if="printMode === 'bills'">
            <h2 class="text-base font-bold mb-2">Billetes y monedas a retirar</h2>
            <table class="w-full text-sm mb-2">
                <thead>
                    <tr class="border-b-2 border-black text-left">
                        <th class="py-1 pr-4">Denominación</th>
                        <th class="py-1 pr-4 text-right">Cantidad</th>
                        <th class="py-1 text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in globalRows" :key="`print-${row.denom}`" class="border-b border-gray-400">
                        <td class="py-1 pr-4">{{ formatPieces(row.denom) }}</td>
                        <td class="py-1 pr-4 text-right font-bold">{{ row.count }}</td>
                        <td class="py-1 text-right">{{ formatCurrency(row.denom * row.count) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <td class="py-1 pr-4">Total ({{ globalPieces }} piezas)</td>
                        <td></td>
                        <td class="py-1 text-right">{{ formatCurrency(globalAmount) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p v-if="globalCalc.leftover > 0" class="text-sm mb-2">
                &#9888; Faltan {{ formatCurrency(globalCalc.leftover) }} que no se pueden formar con las denominaciones elegidas.
            </p>
            <p class="text-xs mb-6">Denominaciones usadas: {{ activeDenoms.map((d) => `$${d}`).join(', ') }}</p>
            </template>

            <template v-if="printMode === 'envelopes'">
            <h2 class="text-base font-bold mb-2">Sobres por empleado (pendientes de cobro)</h2>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-black text-left">
                        <th class="py-1 pr-3">Empleado</th>
                        <th class="py-1 pr-3 text-right">Total a cobrar</th>
                        <th class="py-1">Billetes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="payout in pendingPayouts" :key="`print-emp-${payout.id}`" class="border-b border-gray-400">
                        <td class="py-1 pr-3">{{ payout.employee_name }} <span class="text-xs">({{ payout.employee_number }})</span></td>
                        <td class="py-1 pr-3 text-right font-bold">{{ formatCurrency(collectable(payout)) }}</td>
                        <td class="py-1 text-xs">{{ breakdownLabel(collectable(payout)) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-bold">
                        <td class="py-1 pr-3">Total ({{ pendingPayouts.length }} empleados)</td>
                        <td class="py-1 pr-3 text-right">{{ formatCurrency(pendingTotal) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </template>

            <div class="mt-12 flex gap-16 text-sm">
                <div class="flex-1 border-t border-black pt-1">Preparó — nombre y firma</div>
                <div class="flex-1 border-t border-black pt-1">Recibió — nombre y firma</div>
            </div>
        </section>

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

                    <!-- Detalle concepto por concepto (solo lo que sí tuvo) -->
                    <div v-if="activePayout?.cash_items?.length" class="px-6 py-4 border-b border-gray-100 bg-gray-50">
                        <p class="text-xs font-medium text-gray-500 mb-2">Detalle del efectivo</p>
                        <ul class="space-y-1">
                            <li v-for="(it, i) in activePayout.cash_items" :key="i" class="flex justify-between text-sm">
                                <span class="text-gray-600">
                                    {{ it.label }}
                                    <span v-if="it.detail" class="text-xs text-gray-400">({{ it.detail }})</span>
                                </span>
                                <span :class="it.amount < 0 ? 'text-red-600' : 'text-gray-800'">{{ formatCurrency(it.amount) }}</span>
                            </li>
                        </ul>
                        <div class="flex justify-between text-sm font-semibold text-gray-800 border-t mt-2 pt-2">
                            <span>Efectivo del periodo</span>
                            <span>{{ formatCurrency(activePayout.period_amount) }}</span>
                        </div>
                        <div v-if="activePayout.opening_balance > 0" class="flex justify-between text-xs text-amber-600 mt-1">
                            <span>Acumulado de periodos anteriores</span>
                            <span>+{{ formatCurrency(activePayout.opening_balance) }}</span>
                        </div>
                        <div v-if="activePayout.amount_paid > 0" class="flex justify-between text-xs text-gray-400 mt-1">
                            <span>Ya cobrado</span>
                            <span>-{{ formatCurrency(activePayout.amount_paid) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-pink-700 border-t mt-2 pt-2">
                            <span>Total a cobrar</span>
                            <span>{{ formatCurrency(collectable(activePayout)) }}</span>
                        </div>
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

        <!-- Close collection modal -->
        <div v-if="showCloseCollection" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showCloseCollection = false"></div>

                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Cerrar nómina</h3>
                        <p class="text-sm text-gray-500 mt-1">{{ period.name }}</p>
                    </div>

                    <div class="px-6 py-4">
                        <template v-if="summary.pending_count > 0">
                            <p class="text-sm text-gray-600">
                                Quedan <span class="font-semibold">{{ summary.pending_count }}</span> empleado(s) sin cobrar.
                            </p>
                            <p class="text-2xl font-bold text-pink-700 mt-2">
                                Efectivo a regresar: {{ formatCurrency(summary.total_pending) }}
                            </p>
                        </template>
                        <template v-else>
                            <p class="text-sm text-gray-600">
                                Todos los empleados cobraron. No hay efectivo por regresar.
                            </p>
                        </template>
                        <p class="text-xs text-gray-500 mt-3">
                            Al cerrar ya no se podrá cobrar en este periodo. El efectivo no cobrado se regresa a la empresa
                            y el saldo de cada empleado se acumula automáticamente a la siguiente semana.
                        </p>
                    </div>

                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                        <button type="button" @click="showCloseCollection = false" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                            Cancelar
                        </button>
                        <button
                            type="button"
                            :disabled="closeCollectionForm.processing"
                            @click="submitCloseCollection"
                            class="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 disabled:opacity-50"
                        >
                            {{ closeCollectionForm.processing ? 'Cerrando...' : 'Cerrar nómina' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
