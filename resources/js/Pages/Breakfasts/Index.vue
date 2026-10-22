<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Consulta de desayunos entregados: filtro por rango de fechas (default lo
// pendiente de pagar: del día siguiente al último corte hasta hoy), totales,
// evidencia fotográfica y cortes de pago al vendedor (Luis 2026-10-22).
const props = defineProps({
    claims: Array,
    filters: Object,
    totals: Object,
    vendor: Object,
    canRegister: Boolean,
    canPay: Boolean,
    payouts: Array,
    nextPayoutStart: String,
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const evidenceUrl = ref(null);
const confirmingPayout = ref(false);
const undoingPayout = ref(null);

const payoutForm = useForm({ start_date: '', end_date: '' });
const undoForm = useForm({});

// "Pagado hasta" = día anterior al siguiente inicio permitido.
const paidThrough = computed(() => (props.payouts.length ? props.payouts[0].end_date : null));

const openPayoutConfirm = () => {
    payoutForm.clearErrors();
    confirmingPayout.value = true;
};

const registerPayout = () => {
    payoutForm.start_date = props.filters.start_date;
    payoutForm.end_date = props.filters.end_date;
    payoutForm.post(route('breakfasts.payouts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            confirmingPayout.value = false;
            router.get(route('breakfasts.index'), {}, { preserveScroll: true });
        },
    });
};

const undoPayout = () => {
    undoForm.delete(route('breakfasts.payouts.destroy', undoingPayout.value.id), {
        preserveScroll: true,
        onFinish: () => { undoingPayout.value = null; },
    });
};

const formatDate = (date) => date ? date.split('-').reverse().join('/') : '';

const applyFilters = () => {
    router.get(route('breakfasts.index'), {
        start_date: startDate.value,
        end_date: endDate.value,
    }, { preserveState: true, preserveScroll: true });
};

const formatCurrency = (amount) => new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
}).format(amount || 0);
</script>

<template>
    <Head title="Desayunos" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Desayunos</h2>
                <Link
                    v-if="canRegister"
                    :href="route('breakfasts.kiosk')"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
                >
                    Abrir kiosco
                </Link>
            </div>
        </template>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow p-4 mb-6 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                <input v-model="startDate" type="date" class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                <input v-model="endDate" type="date" class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
            </div>
            <button
                type="button"
                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
                @click="applyFilters"
            >
                Aplicar
            </button>
            <a
                :href="route('breakfasts.export', { start_date: filters.start_date, end_date: filters.end_date })"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
            >
                Exportar a Excel
            </a>
        </div>

        <!-- Totales -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Desayunos entregados</p>
                <p class="text-3xl font-bold text-gray-800">{{ totals.count }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Pendiente de pagar al vendedor</p>
                <p class="text-3xl font-bold text-pink-600">{{ formatCurrency(totals.pending_amount) }}</p>
                <p class="text-xs text-gray-500 mt-1">
                    {{ totals.pending_count }} sin pagar de {{ totals.count }} en el rango ({{ formatCurrency(totals.amount) }})
                </p>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Vendedor</p>
                <p v-if="vendor" class="text-lg font-semibold text-gray-800">{{ vendor.full_name }}</p>
                <p v-if="vendor" class="text-sm text-gray-500">{{ vendor.employee_number }}</p>
                <p v-else class="text-sm text-amber-600 mt-1">Sin vendedor configurado — configúralo en Configuración → Desayunos.</p>
            </div>
        </div>

        <!-- Pago al vendedor -->
        <div v-if="canPay" class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-800">Pago al vendedor</h3>
                    <p class="text-sm text-gray-500">
                        <template v-if="paidThrough">Pagado hasta el {{ formatDate(paidThrough) }}. El siguiente pago empieza el {{ formatDate(nextPayoutStart) }}.</template>
                        <template v-else>Todavía no hay pagos registrados.</template>
                        Elige Desde/Hasta arriba (puedes cortar antes por descansos o festivos), dale Aplicar y registra el pago: se le suma al vendedor en su nómina semanal abierta.
                    </p>
                </div>
                <button
                    type="button"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    :disabled="totals.pending_count === 0"
                    @click="openPayoutConfirm"
                >
                    Registrar pago del {{ formatDate(filters.start_date) }} al {{ formatDate(filters.end_date) }}
                </button>
            </div>
            <p v-if="payoutForm.errors.start_date || payoutForm.errors.end_date" class="mt-3 text-sm text-red-600">
                {{ payoutForm.errors.start_date || payoutForm.errors.end_date }}
            </p>

            <table v-if="payouts.length" class="min-w-full divide-y divide-gray-200 mt-4">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Del</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Al</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Desayunos</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Monto</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Se paga en</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Registró</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <tr v-for="(payout, index) in payouts" :key="payout.id">
                        <td class="px-3 py-2 text-sm text-gray-700">{{ formatDate(payout.start_date) }}</td>
                        <td class="px-3 py-2 text-sm text-gray-700">{{ formatDate(payout.end_date) }}</td>
                        <td class="px-3 py-2 text-sm text-gray-800 text-right">{{ payout.claims_count }}</td>
                        <td class="px-3 py-2 text-sm text-gray-800 text-right">{{ formatCurrency(payout.amount) }}</td>
                        <td class="px-3 py-2 text-sm text-gray-700">
                            <span v-if="payout.payroll">{{ payout.payroll }}</span>
                            <span v-else class="text-amber-600">La próxima nómina semanal</span>
                        </td>
                        <td class="px-3 py-2 text-sm text-gray-500">{{ payout.paid_by || '—' }} · {{ payout.paid_at }}</td>
                        <td class="px-3 py-2 text-right">
                            <button
                                v-if="index === 0 && !payout.locked"
                                type="button"
                                class="text-sm text-red-600 hover:text-red-800"
                                @click="undoingPayout = payout"
                            >
                                Deshacer
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Tabla -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hora</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Departamento</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Costo</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Rostro</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registró</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Evidencia</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <tr v-for="claim in claims" :key="claim.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ claim.claim_date }}
                            <span v-if="claim.paid" class="ml-1 px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pagado</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ claim.claimed_at.slice(11) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-800">
                            {{ claim.employee_name }}
                            <span class="text-gray-400 ml-1">{{ claim.employee_number }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ claim.department || '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-800 text-right">{{ formatCurrency(claim.unit_cost) }}</td>
                        <td class="px-4 py-3 text-sm text-center">
                            <span v-if="claim.face_match_distance !== null" class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">
                                {{ claim.face_match_distance.toFixed(2) }}
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ claim.registered_by || '—' }}</td>
                        <td class="px-4 py-3 text-center">
                            <button
                                v-if="claim.evidence_url"
                                type="button"
                                class="text-pink-600 hover:text-pink-800 text-sm font-medium"
                                @click="evidenceUrl = claim.evidence_url"
                            >
                                Ver foto
                            </button>
                            <span v-else class="text-gray-400 text-sm">—</span>
                        </td>
                    </tr>
                    <tr v-if="claims.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-gray-500">
                            No hay desayunos registrados en el rango seleccionado.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Confirmar pago -->
        <Modal :show="confirmingPayout" @close="confirmingPayout = false" max-width="md">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-800">Registrar pago al vendedor</h3>
                <p class="mt-2 text-sm text-gray-600">
                    Del {{ formatDate(filters.start_date) }} al {{ formatDate(filters.end_date) }}:
                    <strong>{{ totals.pending_count }} desayunos, {{ formatCurrency(totals.pending_amount) }}</strong>.
                </p>
                <p class="mt-2 text-sm text-amber-700">Una vez registrado ya no se puede pagar ninguna fecha hasta el {{ formatDate(filters.end_date) }}.</p>
                <p v-if="payoutForm.errors.start_date || payoutForm.errors.end_date" class="mt-3 text-sm text-red-600">
                    {{ payoutForm.errors.start_date || payoutForm.errors.end_date }}
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" @click="confirmingPayout = false">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                        :disabled="payoutForm.processing"
                        @click="registerPayout"
                    >
                        Registrar pago
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Confirmar deshacer -->
        <Modal :show="!!undoingPayout" @close="undoingPayout = null" max-width="md">
            <div v-if="undoingPayout" class="p-6">
                <h3 class="text-lg font-semibold text-gray-800">Deshacer pago</h3>
                <p class="mt-2 text-sm text-gray-600">
                    El pago del {{ formatDate(undoingPayout.start_date) }} al {{ formatDate(undoingPayout.end_date) }}
                    ({{ undoingPayout.claims_count }} desayunos, {{ formatCurrency(undoingPayout.amount) }}) se borra y esos desayunos quedan otra vez sin pagar.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" @click="undoingPayout = null">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                        :disabled="undoForm.processing"
                        @click="undoPayout"
                    >
                        Deshacer
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Modal de evidencia -->
        <Modal :show="!!evidenceUrl" @close="evidenceUrl = null" max-width="lg">
            <div class="p-4">
                <img v-if="evidenceUrl" :src="evidenceUrl" class="w-full rounded-lg" />
                <div class="mt-4 text-right">
                    <button
                        type="button"
                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                        @click="evidenceUrl = null"
                    >
                        Cerrar
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
