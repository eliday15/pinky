<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    month: String,          // YYYY-MM
    monthLabel: String,
    concepts: Array,
    metricsError: [String, null],
});

const page = usePage();
const flash = computed(() => page.props.flash || {});

const selectedMonth = ref(props.month);
const processing = ref(false);

const verMes = () => {
    router.get(route('maquila-bonuses.index'), { month: selectedMonth.value }, {
        preserveScroll: true,
    });
};

const generar = () => {
    processing.value = true;
    router.post(route('maquila-bonuses.generate'), { month: props.month }, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
};

const money = (n) =>
    n == null ? '—' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n);
const num = (n) => (n == null ? '—' : new Intl.NumberFormat('es-MX').format(n));

// Monto estimado por empleado asignado = cantidad × costo/unidad.
const perEmployee = (c) =>
    c.quantity != null && c.cost_per_unit != null ? c.quantity * c.cost_per_unit : null;
// Total del concepto = por empleado × empleados asignados.
const conceptTotal = (c) =>
    perEmployee(c) != null ? perEmployee(c) * c.assigned_count : null;

const grandTotal = computed(() =>
    props.concepts.reduce((acc, c) => acc + (conceptTotal(c) || 0), 0));

const anyConfigPending = computed(() =>
    props.concepts.some((c) => !c.exists || c.cost_per_unit === 0 || c.assigned_count === 0));
</script>

<template>
    <Head title="Bonos de Maquila" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Bonos de Maquila</h2>
        </template>

        <div class="max-w-6xl">
            <!-- Flash -->
            <div v-if="flash.success" class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                {{ flash.error }}
            </div>

            <!-- Explicación -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-sm text-blue-700">
                <p class="font-medium mb-1">¿Cómo funciona?</p>
                <p>
                    La <strong>cantidad</strong> de cada bono se calcula sola desde la base de producción
                    (basemaquila) por mes — nadie la captura. Tú asignas <strong>a qué empleados</strong> aplica cada
                    bono y el <strong>costo por unidad</strong> desde
                    <Link :href="route('compensation-types.index')" class="underline">Conceptos</Link>.
                    Al generar, se crean autorizaciones <strong>pendientes</strong> (cantidad × costo por empleado
                    asignado) que <strong>sólo el superadmin aprueba</strong>. Se ejecuta automático el día 1 y también
                    con el botón de aquí.
                </p>
            </div>

            <!-- Selector de mes + generar -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="month" class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                        <input
                            id="month"
                            v-model="selectedMonth"
                            type="month"
                            class="rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            @change="verMes"
                        />
                    </div>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
                        @click="verMes"
                    >
                        Ver mes
                    </button>
                    <div class="flex-1"></div>
                    <button
                        type="button"
                        :disabled="processing || !!metricsError"
                        class="inline-flex items-center rounded-md bg-pink-600 px-4 py-2 text-sm font-medium text-white hover:bg-pink-700 disabled:opacity-50"
                        @click="generar"
                    >
                        {{ processing ? 'Generando…' : 'Generar / regenerar autorizaciones de ' + monthLabel }}
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-500">
                    Regenerar actualiza las autorizaciones aún pendientes y respeta (no toca) las ya aprobadas o pagadas.
                </p>
            </div>

            <!-- Error de basemaquila -->
            <div v-if="metricsError" class="mb-6 rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                {{ metricsError }}
            </div>

            <!-- Aviso de configuración -->
            <div v-if="anyConfigPending" class="mb-6 rounded-lg bg-yellow-50 border border-yellow-200 p-4 text-sm text-yellow-800">
                Hay conceptos sin costo por unidad o sin empleados asignados: no generarán autorización hasta configurarlos en
                <Link :href="route('compensation-types.index')" class="underline">Conceptos</Link>.
            </div>

            <!-- Tabla de conceptos -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <th class="px-4 py-3">Bono</th>
                            <th class="px-4 py-3 text-right">Cantidad del mes</th>
                            <th class="px-4 py-3 text-right">Costo / unidad</th>
                            <th class="px-4 py-3 text-right">Empleados</th>
                            <th class="px-4 py-3 text-right">Total estimado</th>
                            <th class="px-4 py-3 text-center">Autorizaciones</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="c in concepts" :key="c.code">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ c.name }}</div>
                                <div class="text-xs text-gray-500">{{ c.description }}</div>
                                <span
                                    v-if="c.exists && !c.approver_restricted"
                                    class="mt-1 inline-block rounded bg-red-50 px-1.5 py-0.5 text-[11px] text-red-700"
                                >
                                    ⚠ aprobación no restringida a superadmin
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900">
                                {{ num(c.quantity) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="c.cost_per_unit === 0 ? 'text-yellow-600' : 'text-gray-700'">
                                {{ money(c.cost_per_unit) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums" :class="c.assigned_count === 0 ? 'text-yellow-600' : 'text-gray-700'">
                                {{ c.assigned_count }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-900">
                                {{ money(conceptTotal(c)) }}
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                <span v-if="c.authorizations.pending" class="mr-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">{{ c.authorizations.pending }} pend.</span>
                                <span v-if="c.authorizations.approved" class="mr-1 rounded bg-green-100 px-1.5 py-0.5 text-green-800">{{ c.authorizations.approved }} aprob.</span>
                                <span v-if="c.authorizations.paid" class="mr-1 rounded bg-blue-100 px-1.5 py-0.5 text-blue-800">{{ c.authorizations.paid }} pag.</span>
                                <span v-if="c.authorizations.rejected" class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600">{{ c.authorizations.rejected }} rech.</span>
                                <span v-if="!c.authorizations.pending && !c.authorizations.approved && !c.authorizations.paid && !c.authorizations.rejected" class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Link
                                    v-if="c.exists"
                                    :href="route('compensation-types.edit', c.compensation_type_id)"
                                    class="text-pink-600 hover:text-pink-800"
                                >
                                    Configurar
                                </Link>
                                <span v-else class="text-xs text-red-600">falta seed</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr class="font-semibold text-gray-900">
                            <td class="px-4 py-3" colspan="4">Total estimado del mes</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money(grandTotal) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
