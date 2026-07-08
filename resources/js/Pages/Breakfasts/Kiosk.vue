<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onBeforeUnmount } from 'vue';
import FaceScan from './components/FaceScan.vue';
import PinPad from './components/PinPad.vue';

// Kiosco de desayunos: (1) número de empleado → (2) verificación facial →
// (3) NIP → desayuno registrado. El servidor re-valida todo (NIP, ventana
// antes de la hora de entrada, 1 por día); esta pantalla solo guía el flujo.
const props = defineProps({
    faceMaxDistance: { type: Number, default: 0.5 },
    breakfastCost: { type: Number, default: 0 },
});

const step = ref('lookup'); // lookup | eligibility | face | pin | success | error
const employeeNumber = ref('');
const employee = ref(null);
const status = ref(null);
const lookupError = ref('');
const loading = ref(false);
const faceResult = ref(null);
const pin = ref('');
const pinError = ref('');
const resultMessage = ref('');
const claim = ref(null);

let resetTimer = null;

const reset = () => {
    if (resetTimer) {
        clearTimeout(resetTimer);
        resetTimer = null;
    }
    step.value = 'lookup';
    employeeNumber.value = '';
    employee.value = null;
    status.value = null;
    lookupError.value = '';
    loading.value = false;
    faceResult.value = null;
    pin.value = '';
    pinError.value = '';
    resultMessage.value = '';
    claim.value = null;
};

const scheduleReset = (ms) => {
    resetTimer = setTimeout(reset, ms);
};

const firstError = (error, fallback) => {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors)[0];
        return Array.isArray(first) ? first[0] : first;
    }
    return error?.response?.data?.message || fallback;
};

const lookup = async () => {
    if (!employeeNumber.value.trim() || loading.value) return;
    loading.value = true;
    lookupError.value = '';
    try {
        const { data } = await axios.post(route('breakfasts.lookup'), {
            employee_number: employeeNumber.value.trim(),
        });
        employee.value = data.employee;
        status.value = data.status;
        step.value = 'eligibility';
    } catch (error) {
        lookupError.value = firstError(error, 'No se pudo buscar al empleado.');
    } finally {
        loading.value = false;
    }
};

const startFaceScan = () => {
    step.value = 'face';
};

const onFaceVerified = ({ distance, snapshot }) => {
    faceResult.value = { distance, snapshot };
    step.value = 'pin';
};

const onFaceError = (message) => {
    resultMessage.value = message;
    step.value = 'error';
    scheduleReset(8000);
};

const submitPin = async () => {
    if (loading.value) return;
    loading.value = true;
    pinError.value = '';
    try {
        const { data } = await axios.post(route('breakfasts.store'), {
            employee_id: employee.value.id,
            pin: pin.value,
            face_distance: faceResult.value.distance,
            evidence: faceResult.value.snapshot,
        });
        claim.value = data.claim;
        step.value = 'success';
        scheduleReset(6000);
    } catch (error) {
        const errors = error?.response?.data?.errors;
        if (errors?.pin) {
            // NIP incorrecto: se queda en el teclado para reintentar.
            pinError.value = Array.isArray(errors.pin) ? errors.pin[0] : errors.pin;
            pin.value = '';
        } else {
            resultMessage.value = firstError(error, 'No se pudo registrar el desayuno.');
            step.value = 'error';
            scheduleReset(8000);
        }
    } finally {
        loading.value = false;
    }
};

onBeforeUnmount(() => {
    if (resetTimer) clearTimeout(resetTimer);
});
</script>

<template>
    <Head title="Kiosco de Desayunos" />

    <AppLayout>
        <div class="max-w-2xl mx-auto py-8 px-4">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">🍳 Desayunos</h1>
                <p class="text-gray-500 mt-1">Cobra tu desayuno antes de tu hora de entrada</p>
            </div>

            <!-- Paso 1: identificación -->
            <div v-if="step === 'lookup'" class="bg-white rounded-2xl shadow p-8">
                <label class="block text-lg font-medium text-gray-700 mb-3 text-center">Número de empleado</label>
                <input
                    v-model="employeeNumber"
                    type="text"
                    inputmode="numeric"
                    autofocus
                    class="w-full text-center text-4xl tracking-widest rounded-2xl border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 py-4"
                    @keyup.enter="lookup"
                />
                <p v-if="lookupError" class="mt-3 text-center text-red-600">{{ lookupError }}</p>
                <button
                    type="button"
                    :disabled="loading || !employeeNumber.trim()"
                    class="mt-6 w-full py-4 rounded-2xl bg-pink-600 text-white text-xl font-semibold shadow hover:bg-pink-700 disabled:opacity-50"
                    @click="lookup"
                >
                    {{ loading ? 'Buscando...' : 'Continuar' }}
                </button>
            </div>

            <!-- Paso 1b: elegibilidad -->
            <div v-else-if="step === 'eligibility'" class="bg-white rounded-2xl shadow p-8 text-center">
                <img
                    v-if="employee.photo_url"
                    :src="employee.photo_url"
                    class="w-28 h-28 rounded-full object-cover mx-auto mb-4 ring-4 ring-pink-200"
                />
                <h2 class="text-2xl font-bold text-gray-800">{{ employee.full_name }}</h2>
                <p class="text-gray-500">{{ employee.employee_number }}<span v-if="employee.department"> · {{ employee.department }}</span></p>

                <template v-if="status.eligible">
                    <p v-if="status.window" class="mt-4 text-gray-600">
                        Tu ventana de hoy: <span class="font-semibold">{{ status.window.start }} – {{ status.window.end }}</span>
                    </p>
                    <button
                        type="button"
                        class="mt-6 w-full py-4 rounded-2xl bg-pink-600 text-white text-xl font-semibold shadow hover:bg-pink-700"
                        @click="startFaceScan"
                    >
                        Verificar mi rostro
                    </button>
                </template>
                <template v-else>
                    <div class="mt-6 rounded-2xl bg-red-50 border border-red-200 p-4">
                        <p class="text-red-700 text-lg">{{ status.reason }}</p>
                    </div>
                </template>
                <button type="button" class="mt-4 w-full py-3 rounded-2xl bg-gray-100 text-gray-600 font-medium hover:bg-gray-200" @click="reset">
                    Cancelar
                </button>
            </div>

            <!-- Paso 2: rostro -->
            <div v-else-if="step === 'face'" class="bg-white rounded-2xl shadow p-8">
                <h2 class="text-xl font-semibold text-gray-800 text-center mb-4">Hola {{ employee.full_name }}, mira a la cámara</h2>
                <FaceScan
                    :photo-url="employee.photo_url"
                    :max-distance="faceMaxDistance"
                    @verified="onFaceVerified"
                    @error="onFaceError"
                />
                <button type="button" class="mt-6 w-full py-3 rounded-2xl bg-gray-100 text-gray-600 font-medium hover:bg-gray-200" @click="reset">
                    Cancelar
                </button>
            </div>

            <!-- Paso 3: NIP -->
            <div v-else-if="step === 'pin'" class="bg-gray-50 rounded-2xl shadow p-8">
                <h2 class="text-xl font-semibold text-gray-800 text-center mb-2">Rostro verificado ✓</h2>
                <p class="text-gray-500 text-center mb-6">Teclea tu NIP de desayunos</p>
                <PinPad v-model="pin" :disabled="loading" @submit="submitPin" />
                <p v-if="pinError" class="mt-4 text-center text-red-600 text-lg">{{ pinError }}</p>
                <button type="button" class="mt-6 w-full py-3 rounded-2xl bg-gray-100 text-gray-600 font-medium hover:bg-gray-200" @click="reset">
                    Cancelar
                </button>
            </div>

            <!-- Éxito -->
            <div v-else-if="step === 'success'" class="bg-green-600 rounded-2xl shadow p-10 text-center text-white">
                <p class="text-6xl mb-4">✅</p>
                <h2 class="text-3xl font-bold">¡Buen provecho!</h2>
                <p class="mt-2 text-xl">{{ claim.employee_name }}</p>
                <p class="text-green-100 mt-1">Desayuno registrado a las {{ claim.claimed_at }}</p>
                <button type="button" class="mt-8 px-8 py-3 rounded-2xl bg-white/20 font-semibold hover:bg-white/30" @click="reset">
                    Siguiente empleado
                </button>
            </div>

            <!-- Error terminal -->
            <div v-else-if="step === 'error'" class="bg-red-600 rounded-2xl shadow p-10 text-center text-white">
                <p class="text-6xl mb-4">❌</p>
                <h2 class="text-2xl font-bold">No se pudo entregar</h2>
                <p class="mt-3 text-lg text-red-100">{{ resultMessage }}</p>
                <button type="button" class="mt-8 px-8 py-3 rounded-2xl bg-white/20 font-semibold hover:bg-white/30" @click="reset">
                    Intentar de nuevo
                </button>
            </div>
        </div>
    </AppLayout>
</template>
