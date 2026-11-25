<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDate as fmtDate } from '@/utils/date';

// PRENÓMINA (Luis 2026-11-25): lo que va a cobrar cada quien en la semanal y
// en la mensual ANTES de generar la nómina. Nada de esto se guarda.
const props = defineProps({
    scopes: Array,
    filters: Object,
    departments: Array,
    // { rows, totals } — llega después de pintar la pantalla (prop diferida).
    result: { type: Object, default: null },
    withAmounts: Boolean,
});

const form = ref({
    scope: props.filters.scope,
    week_start: props.filters.week_start,
    week_end: props.filters.week_end,
    extras_start: props.filters.extras_start,
    extras_end: props.filters.extras_end,
    with_extras: props.filters.extras_start !== null,
});
const loading = ref(false);

const recalculate = () => {
    const params = {
        scope: form.value.scope ?? undefined,
        week_start: form.value.week_start,
        week_end: form.value.week_end,
    };
    if (form.value.with_extras) {
        params.extras_start = form.value.extras_start;
        params.extras_end = form.value.extras_end;
    } else {
        params.without_extras = 1;
    }
    router.get(route('payroll.prenomina'), params, {
        preserveScroll: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
};

// Al cambiar de alcance (General / Taller) se vuelven a sugerir sus fechas.
const changeScope = () => {
    router.get(route('payroll.prenomina'), { scope: form.value.scope ?? undefined }, {
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
};

const department = ref('');
const search = ref('');
const onlyAlerts = ref(false);
const open = ref(new Set());

const normalize = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const rows = computed(() => props.result?.rows ?? []);

const filtered = computed(() => rows.value.filter((r) => {
    if (department.value !== '' && Number(r.department_id) !== Number(department.value)) return false;
    if (onlyAlerts.value && !(r.alerts ?? []).length) return false;
    const q = normalize(search.value).trim();
    if (q && !normalize(`${r.name} ${r.employee_number ?? ''}`).includes(q)) return false;
    return true;
}));

const sum = (pick) => filtered.value.reduce((s, r) => s + Number(pick(r) || 0), 0);
const filteredTotals = computed(() => ({
    weekly: sum((r) => r.weekly?.net),
    monthly: sum((r) => r.monthly?.net),
    total: sum((r) => r.total),
}));

const toggle = (id) => {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
};

const money = (n) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(n || 0);
const formatDate = (d) => (d ? fmtDate(d, { day: 'numeric', month: 'short', year: 'numeric' }) : '');
const alertCount = computed(() => rows.value.filter((r) => (r.alerts ?? []).length).length);
</script>

<template>
    <Head title="Prenómina" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Prenómina</h2>
        </template>

        <div class="space-y-6">
            <div class="rounded-lg border border-pink-200 bg-pink-50 p-4 text-sm text-pink-900">
                Lo que va a cobrar cada persona si hoy se generara la nómina, con las mismas reglas.
                <span class="font-medium">No se guarda nada:</span> corrige lo que no cuadre (autorizaciones, conceptos, incidencias) y
                vuelve a calcular. Cuando todo esté bien, genera la nómina como siempre.
            </div>

            <!-- Qué nómina se simula -->
            <div class="rounded-lg bg-white p-4 shadow">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block text-sm">
                        <span class="text-gray-700">Nómina</span>
                        <select v-model="form.scope" class="mt-1 block w-full rounded-md border-gray-300 text-sm" @change="changeScope">
                            <option v-for="s in scopes" :key="s.id ?? 'general'" :value="s.id">{{ s.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-700">Semana: del</span>
                        <input v-model="form.week_start" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </label>
                    <label class="block text-sm">
                        <span class="text-gray-700">al</span>
                        <input v-model="form.week_end" type="date" class="mt-1 block w-full rounded-md border-gray-300 text-sm" />
                    </label>
                    <div v-if="filters.can_have_extras" class="text-sm">
                        <label class="flex items-center gap-2 text-gray-700">
                            <input v-model="form.with_extras" type="checkbox" class="rounded border-gray-300 text-pink-600" />
                            Incluir los extras del mes (mensual)
                        </label>
                        <div v-if="form.with_extras" class="mt-1 grid grid-cols-2 gap-2">
                            <input v-model="form.extras_start" type="date" class="block w-full rounded-md border-gray-300 text-sm" aria-label="Extras desde" />
                            <input v-model="form.extras_end" type="date" class="block w-full rounded-md border-gray-300 text-sm" aria-label="Extras hasta" />
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="rounded-md bg-pink-600 px-4 py-2 text-sm font-medium text-white hover:bg-pink-700 disabled:opacity-50"
                        :disabled="loading"
                        @click="recalculate"
                    >
                        {{ loading ? 'Calculando…' : 'Volver a calcular' }}
                    </button>
                    <span class="text-xs text-gray-500">
                        Semana {{ formatDate(filters.week_start) }} – {{ formatDate(filters.week_end) }}
                        <template v-if="filters.extras_start">
                            · extras del mes {{ formatDate(filters.extras_start) }} – {{ formatDate(filters.extras_end) }}
                        </template>
                    </span>
                </div>
            </div>

            <Deferred data="result">
                <template #fallback>
                    <div class="rounded-lg bg-white p-8 text-center text-sm text-gray-500 shadow">
                        Calculando la prenómina de toda la plantilla… tarda alrededor de medio minuto.
                    </div>
                </template>

                <div v-if="alertCount" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                    {{ alertCount }} persona(s) con algo que revisar (concepto repetido el mismo día o concepto sin monto).
                    <button type="button" class="ml-2 underline" @click="onlyAlerts = !onlyAlerts">
                        {{ onlyAlerts ? 'Ver a todos' : 'Ver solo esas' }}
                    </button>
                </div>

                <!-- Filtros de la lista -->
                <div class="flex flex-col gap-3 sm:flex-row">
                    <select v-model="department" class="rounded-md border-gray-300 text-sm sm:w-64">
                        <option value="">Todos los departamentos</option>
                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                    <input v-model="search" type="search" placeholder="Buscar empleado (nombre o número)" class="rounded-md border-gray-300 text-sm sm:w-80" />
                    <span class="self-center text-sm text-gray-500">{{ filtered.length }} de {{ rows.length }} empleados</span>
                </div>

                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Empleado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Departamento</th>
                                <th v-if="withAmounts" class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Semanal</th>
                                <th v-if="withAmounts" class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Mensual</th>
                                <th v-if="withAmounts" class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Total a cobrar</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Desglose</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="r in filtered" :key="r.employee_id">
                                <tr class="cursor-pointer hover:bg-gray-50" @click="toggle(r.employee_id)">
                                    <td class="px-4 py-2">
                                        <span class="font-medium text-gray-900">{{ r.name }}</span>
                                        <span v-if="r.employee_number" class="ml-1 text-xs text-gray-400">#{{ r.employee_number }}</span>
                                        <span v-if="(r.alerts ?? []).length" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">revisar</span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600">{{ r.department ?? '—' }}</td>
                                    <td v-if="withAmounts" class="px-4 py-2 text-right tabular-nums">{{ r.weekly ? money(r.weekly.net) : '—' }}</td>
                                    <td v-if="withAmounts" class="px-4 py-2 text-right tabular-nums">{{ r.monthly ? money(r.monthly.net) : '—' }}</td>
                                    <td v-if="withAmounts" class="px-4 py-2 text-right font-semibold tabular-nums">{{ money(r.total) }}</td>
                                    <td class="px-4 py-2 text-right text-pink-600">{{ open.has(r.employee_id) ? 'Ocultar' : 'Ver' }}</td>
                                </tr>
                                <tr v-if="open.has(r.employee_id)" class="bg-gray-50">
                                    <td :colspan="withAmounts ? 6 : 3" class="px-4 py-3">
                                        <ul v-if="(r.alerts ?? []).length" class="mb-3 space-y-1">
                                            <li v-for="(a, ai) in r.alerts" :key="ai" class="text-xs text-amber-800">⚠ {{ a }}</li>
                                        </ul>
                                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            <div v-for="pass in [['weekly', 'Semanal'], ['monthly', 'Mensual (extras del mes)']]" :key="pass[0]">
                                                <template v-if="r[pass[0]]">
                                                    <p class="mb-1 text-xs font-semibold uppercase text-gray-500">{{ pass[1] }}</p>
                                                    <table class="w-full text-xs">
                                                        <tr v-for="(l, li) in r[pass[0]].lines" :key="li" class="border-b border-gray-100">
                                                            <td class="py-1 text-gray-800">{{ l.label }}</td>
                                                            <td class="py-1 text-gray-500">{{ l.detail }}</td>
                                                            <td v-if="withAmounts" class="py-1 text-right tabular-nums" :class="l.amount < 0 ? 'text-red-600' : 'text-gray-800'">{{ money(l.amount) }}</td>
                                                        </tr>
                                                        <tr v-if="!r[pass[0]].lines.length">
                                                            <td class="py-1 text-gray-400">Nada que cobrar en esta parte.</td>
                                                        </tr>
                                                        <tr v-if="withAmounts" class="font-semibold">
                                                            <td class="pt-2">A recibir</td>
                                                            <td class="pt-2 text-gray-500">efectivo {{ money(r[pass[0]].cash) }} · transferencia {{ money(r[pass[0]].bank) }}</td>
                                                            <td class="pt-2 text-right tabular-nums">{{ money(r[pass[0]].net) }}</td>
                                                        </tr>
                                                    </table>
                                                </template>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!filtered.length">
                                <td :colspan="withAmounts ? 6 : 3" class="px-4 py-6 text-center text-gray-400">No hay empleados con estos filtros.</td>
                            </tr>
                        </tbody>
                        <tfoot v-if="withAmounts && filtered.length" class="bg-gray-50 font-semibold">
                            <tr>
                                <td class="px-4 py-3" colspan="2">Total ({{ filtered.length }} empleados)</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money(filteredTotals.weekly) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money(filteredTotals.monthly) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money(filteredTotals.total) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </Deferred>
        </div>
    </AppLayout>
</template>
