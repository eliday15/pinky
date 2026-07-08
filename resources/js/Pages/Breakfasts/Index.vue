<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Consulta de desayunos entregados: filtro por rango de fechas (default la
// semana en curso), totales (lo que cobrará el vendedor en su nómina semanal)
// y evidencia fotográfica de cada cobro.
const props = defineProps({
    claims: Array,
    filters: Object,
    totals: Object,
    vendor: Object,
    canRegister: Boolean,
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const evidenceUrl = ref(null);

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
        </div>

        <!-- Totales -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Desayunos entregados</p>
                <p class="text-3xl font-bold text-gray-800">{{ totals.count }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Total a pagar al vendedor</p>
                <p class="text-3xl font-bold text-pink-600">{{ formatCurrency(totals.amount) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <p class="text-sm text-gray-500">Vendedor</p>
                <p v-if="vendor" class="text-lg font-semibold text-gray-800">{{ vendor.full_name }}</p>
                <p v-if="vendor" class="text-sm text-gray-500">{{ vendor.employee_number }}</p>
                <p v-else class="text-sm text-amber-600 mt-1">Sin vendedor configurado — configúralo en Configuración → Desayunos.</p>
            </div>
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
                        <td class="px-4 py-3 text-sm text-gray-700">{{ claim.claim_date }}</td>
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
