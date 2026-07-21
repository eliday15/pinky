<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    configuredDays: Number,
    appliedYear: [Number, null],
    previewDays: Number,
    preview: [Object, null],
    currentYear: Number,
    affected: Array,
});

const dias = ref(props.previewDays || props.configuredDays || 6);
const loadingPreview = ref(false);
const processing = ref(false);

const showApplyModal = ref(false);
const showClearModal = ref(false);
const showSettleModal = ref(false);

const calcularImpacto = () => {
    loadingPreview.value = true;
    router.get(
        route('settings.december-vacation'),
        { dias: dias.value },
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { loadingPreview.value = false; },
        }
    );
};

const aplicar = () => {
    showApplyModal.value = false;
    processing.value = true;
    router.post(
        route('settings.december-vacation.apply'),
        { days: dias.value },
        { onFinish: () => { processing.value = false; } }
    );
};

const liberar = () => {
    showClearModal.value = false;
    processing.value = true;
    router.post(
        route('settings.december-vacation.clear'),
        {},
        { onFinish: () => { processing.value = false; } }
    );
};

const saldar = () => {
    showSettleModal.value = false;
    processing.value = true;
    router.post(
        route('settings.december-vacation.settle'),
        {},
        { onFinish: () => { processing.value = false; } }
    );
};
</script>

<template>
    <Head title="Cierre Obligatorio de Diciembre" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Cierre Obligatorio de Diciembre
            </h2>
        </template>

        <div class="max-w-5xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('settings.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a configuracion
                </Link>
            </div>

            <!-- Explicacion -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex">
                    <svg class="h-5 w-5 text-blue-400 mr-3 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                    <div class="text-sm text-blue-700">
                        <p class="font-medium mb-1">¿Como funciona?</p>
                        <p>
                            La empresa cierra en diciembre. Define cuantos dias de vacaciones son obligatorios
                            para ese cierre; son los mismos para toda la empresa. Esos dias quedan apartados
                            y no se pueden solicitar en otra fecha. A los de nuevo ingreso, que aun no generan
                            derecho, se les adelantan para que no se queden sin sueldo; esa deuda se salda sola
                            cuando generan su derecho.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Estado actual -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Estado actual</h3>
                <div v-if="configuredDays > 0" class="flex items-center space-x-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                        Configurado
                    </span>
                    <span class="text-gray-700">
                        <strong>{{ configuredDays }} dias</strong> apartados para el cierre de diciembre
                        <span v-if="appliedYear" class="text-gray-500">(aplicado en {{ appliedYear }})</span>
                    </span>
                </div>
                <div v-else class="flex items-center space-x-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-600">
                        Sin configurar
                    </span>
                    <span class="text-gray-500">Aun no se han apartado dias para el cierre de diciembre.</span>
                </div>
            </div>

            <!-- Calculadora de impacto -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Calcular impacto</h3>
                <div class="flex items-end gap-4">
                    <div>
                        <label for="dias" class="block text-sm font-medium text-gray-700 mb-1">
                            Dias obligatorios de cierre
                        </label>
                        <input
                            id="dias"
                            v-model.number="dias"
                            type="number"
                            min="1"
                            max="31"
                            class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        />
                    </div>
                    <button
                        type="button"
                        @click="calcularImpacto"
                        :disabled="loadingPreview || !dias"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors text-sm disabled:opacity-50"
                    >
                        {{ loadingPreview ? 'Calculando...' : 'Calcular impacto' }}
                    </button>
                </div>

                <!-- Tarjetas de impacto -->
                <div v-if="preview" class="mt-6">
                    <p class="text-sm text-gray-500 mb-4">
                        Impacto estimado para <strong>{{ previewDays }} dias</strong> de cierre obligatorio:
                    </p>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-gray-800">{{ preview.total }}</p>
                            <p class="text-xs text-gray-500 mt-1">Colaboradores activos</p>
                        </div>
                        <div class="bg-green-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-green-700">{{ preview.con_derecho }}</p>
                            <p class="text-xs text-green-600 mt-1">Cubren con su derecho</p>
                        </div>
                        <div class="bg-amber-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-amber-700">{{ preview.con_adelanto }}</p>
                            <p class="text-xs text-amber-600 mt-1">
                                Reciben adelanto
                                <span v-if="preview.dias_adelantados > 0" class="block font-semibold">
                                    ({{ preview.dias_adelantados }} dias en total)
                                </span>
                            </p>
                        </div>
                        <div class="bg-red-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-red-700">{{ preview.incompletos }}</p>
                            <p class="text-xs text-red-600 mt-1">
                                Incompletos
                                <span class="block font-normal text-gray-400">(se aparta lo que les queda)</span>
                            </p>
                        </div>
                    </div>
                    <p v-if="preview.incompletos > 0" class="mt-3 text-xs text-gray-400">
                        * "Incompletos": colaboradores que no alcanzan a cubrir los dias y no son de nuevo ingreso; se les aparta lo que les queda de su derecho disponible.
                    </p>
                </div>
            </div>

            <!-- Acciones -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Acciones</h3>
                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        @click="showApplyModal = true"
                        :disabled="processing || !dias"
                        class="px-5 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors text-sm font-medium disabled:opacity-50"
                    >
                        Aplicar a toda la empresa
                    </button>
                    <button
                        type="button"
                        @click="showClearModal = true"
                        :disabled="processing"
                        class="px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium disabled:opacity-50"
                    >
                        Liberar dias apartados
                    </button>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="showSettleModal = true"
                            :disabled="processing"
                            class="px-5 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors text-sm font-medium disabled:opacity-50"
                        >
                            Saldar adelantos
                        </button>
                        <span class="text-xs text-gray-400">Descuenta los dias adelantados a quienes ya generaron su derecho.</span>
                    </div>
                </div>
            </div>

            <!-- Tabla de colaboradores afectados -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Colaboradores con dias apartados</h3>
                </div>

                <div v-if="affected.length === 0" class="px-6 py-12 text-center text-gray-500">
                    Todavia no hay dias apartados.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Colaborador
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Derecho
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Usados
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Apartados dic.
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Adelantados
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Para disfrutar
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Disponibles
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="row in affected" :key="row.id" class="hover:bg-gray-50">
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-2">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">{{ row.full_name }}</p>
                                            <p class="text-xs text-gray-400">{{ row.employee_number }}</p>
                                        </div>
                                        <span
                                            v-if="row.is_new_hire"
                                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800"
                                        >
                                            Nuevo ingreso
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-center text-sm text-gray-700">{{ row.entitled }}</td>
                                <td class="px-6 py-3 text-center text-sm text-gray-700">{{ row.used }}</td>
                                <td class="px-6 py-3 text-center text-sm text-gray-700">{{ row.reserved }}</td>
                                <td class="px-6 py-3 text-center text-sm">
                                    <span
                                        :class="row.advanced > 0 ? 'font-semibold text-amber-700' : 'text-gray-700'"
                                    >
                                        {{ row.advanced }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-center text-sm text-gray-700">{{ row.for_enjoyment }}</td>
                                <td class="px-6 py-3 text-center text-sm text-gray-700">{{ row.available }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Modal: Aplicar -->
    <div v-if="showApplyModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40" @click="showApplyModal = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
            <h4 class="text-lg font-semibold text-gray-800 mb-2">¿Aplicar cierre de diciembre?</h4>
            <p class="text-sm text-gray-600 mb-1">
                Se apartaran <strong>{{ dias }} dias</strong> de vacaciones para todos los colaboradores activos.
            </p>
            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3">
                Esto sobrescribe los dias apartados de todos los colaboradores activos, incluidos los capturados a mano.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    @click="showApplyModal = false"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    @click="aplicar"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm font-medium"
                >
                    Si, aplicar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Liberar -->
    <div v-if="showClearModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40" @click="showClearModal = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
            <h4 class="text-lg font-semibold text-gray-800 mb-2">¿Liberar dias apartados?</h4>
            <p class="text-sm text-gray-600 mb-1">
                Se eliminaran todos los dias apartados para el cierre de diciembre.
            </p>
            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-3">
                Esto libera los dias apartados y los adelantos pendientes de todos los colaboradores.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    @click="showClearModal = false"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    @click="liberar"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-sm font-medium"
                >
                    Si, liberar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Saldar -->
    <div v-if="showSettleModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40" @click="showSettleModal = false"></div>
        <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4 p-6">
            <h4 class="text-lg font-semibold text-gray-800 mb-2">¿Saldar adelantos?</h4>
            <p class="text-sm text-gray-600">
                Se descontaran los dias adelantados a quienes ya generaron su derecho de vacaciones. Esta accion no afecta a los colaboradores que aun no tienen derecho.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <button
                    type="button"
                    @click="showSettleModal = false"
                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    @click="saldar"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 text-sm font-medium"
                >
                    Si, saldar
                </button>
            </div>
        </div>
    </div>
</template>
