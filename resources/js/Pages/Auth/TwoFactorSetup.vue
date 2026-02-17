<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import QRCode from 'qrcode';

const props = defineProps({
    qrCodeUri: String,
    secret: String,
    isEnabled: Boolean,
    requiresTwoFactor: Boolean,
    recoveryCodesCount: Number,
    recoveryCodes: {
        type: Array,
        default: () => [],
    },
});

const qrCodeDataUrl = ref('');
const showSecret = ref(false);
const copiedCodes = ref(false);

const confirmForm = useForm({
    code: '',
});

const disableForm = useForm({
    password: '',
});

const regenerateForm = useForm({});

const flash = usePage().props.flash;

onMounted(async () => {
    if (props.qrCodeUri) {
        try {
            qrCodeDataUrl.value = await QRCode.toDataURL(props.qrCodeUri, {
                width: 256,
                margin: 2,
                color: { dark: '#000000', light: '#ffffff' },
            });
        } catch (err) {
            console.error('Error generating QR code:', err);
        }
    }
});

const submitConfirm = () => {
    confirmForm.post(route('two-factor.confirm'), {
        preserveScroll: true,
    });
};

const submitDisable = () => {
    disableForm.delete(route('two-factor.disable'), {
        preserveScroll: true,
        onSuccess: () => disableForm.reset(),
    });
};

const submitRegenerate = () => {
    if (confirm('Los codigos anteriores dejaran de funcionar. ¿Continuar?')) {
        regenerateForm.post(route('two-factor.regenerate-recovery-codes'));
    }
};

const copyRecoveryCodes = () => {
    const text = props.recoveryCodes.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        copiedCodes.value = true;
        setTimeout(() => { copiedCodes.value = false; }, 2000);
    });
};
</script>

<template>
    <Head title="Autenticacion de dos pasos" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Autenticacion de dos pasos
            </h2>
        </template>

        <div class="max-w-2xl mx-auto">
            <!-- Warning banner for forced setup -->
            <div v-if="requiresTwoFactor && !isEnabled" class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex items-center">
                    <svg class="w-5 h-5 text-yellow-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <span class="text-yellow-800 font-medium">
                        Tu rol requiere autenticacion de dos pasos. Configura tu dispositivo para continuar.
                    </span>
                </div>
            </div>

            <!-- Setup State: Show QR Code -->
            <div v-if="!isEnabled && qrCodeUri" class="bg-white rounded-lg shadow p-6 space-y-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Configurar autenticacion de dos pasos</h3>
                    <p class="text-sm text-gray-600">
                        Escanea el codigo QR con tu aplicacion de autenticacion (Google Authenticator, Authy, etc.) y luego ingresa el codigo de 6 digitos para confirmar.
                    </p>
                </div>

                <!-- QR Code -->
                <div class="flex justify-center">
                    <div class="bg-white p-4 border-2 border-gray-200 rounded-lg">
                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="QR Code" class="w-64 h-64" />
                        <div v-else class="w-64 h-64 flex items-center justify-center text-gray-400">
                            Cargando QR...
                        </div>
                    </div>
                </div>

                <!-- Manual Entry -->
                <div class="text-center">
                    <button
                        @click="showSecret = !showSecret"
                        class="text-sm text-pink-600 hover:text-pink-800 underline"
                    >
                        {{ showSecret ? 'Ocultar clave secreta' : 'No puedes escanear? Ingresa la clave manualmente' }}
                    </button>
                    <div v-if="showSecret" class="mt-3 p-3 bg-gray-100 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Clave secreta:</p>
                        <code class="text-sm font-mono font-bold text-gray-800 select-all">{{ secret }}</code>
                    </div>
                </div>

                <!-- Confirmation Form -->
                <form @submit.prevent="submitConfirm">
                    <InputLabel for="code" value="Codigo de verificacion" />
                    <TextInput
                        id="code"
                        v-model="confirmForm.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        class="mt-1 block w-full text-center text-2xl tracking-widest"
                        placeholder="000000"
                        autofocus
                    />
                    <InputError class="mt-2" :message="confirmForm.errors.code" />

                    <PrimaryButton class="mt-4 w-full justify-center" :disabled="confirmForm.processing">
                        {{ confirmForm.processing ? 'Verificando...' : 'Confirmar y activar' }}
                    </PrimaryButton>
                </form>
            </div>

            <!-- Recovery Codes (shown right after setup or when viewing) -->
            <div v-if="recoveryCodes.length > 0" class="bg-white rounded-lg shadow p-6 mt-6">
                <div class="flex items-start mb-4">
                    <svg class="w-6 h-6 text-yellow-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Codigos de recuperacion</h3>
                        <p class="text-sm text-gray-600 mt-1">
                            Guarda estos codigos en un lugar seguro. Cada codigo solo puede usarse una vez. Si pierdes acceso a tu aplicacion de autenticacion, puedes usar estos codigos para iniciar sesion.
                        </p>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-lg p-4 mb-4">
                    <div class="grid grid-cols-2 gap-2">
                        <code
                            v-for="code in recoveryCodes"
                            :key="code"
                            class="text-sm font-mono text-gray-800 bg-white px-3 py-2 rounded border text-center"
                        >
                            {{ code }}
                        </code>
                    </div>
                </div>

                <button
                    @click="copyRecoveryCodes"
                    class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                >
                    {{ copiedCodes ? 'Copiado!' : 'Copiar codigos' }}
                </button>
            </div>

            <!-- Already Enabled State -->
            <div v-if="isEnabled && recoveryCodes.length === 0" class="bg-white rounded-lg shadow p-6 space-y-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-green-800">Autenticacion de dos pasos activa</h3>
                        <p class="text-sm text-gray-600">
                            Tu cuenta esta protegida con autenticacion de dos pasos.
                        </p>
                    </div>
                </div>

                <div class="border-t pt-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-600">Codigos de recuperacion restantes:</span>
                        <span class="text-sm font-semibold" :class="recoveryCodesCount <= 2 ? 'text-red-600' : 'text-gray-800'">
                            {{ recoveryCodesCount }}
                        </span>
                    </div>

                    <button
                        @click="submitRegenerate"
                        :disabled="regenerateForm.processing"
                        class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50"
                    >
                        Regenerar codigos de recuperacion
                    </button>

                    <!-- Disable 2FA (only for non-required roles) -->
                    <div v-if="!requiresTwoFactor" class="border-t pt-4 mt-4">
                        <h4 class="text-sm font-medium text-red-600 mb-3">Desactivar autenticacion de dos pasos</h4>
                        <form @submit.prevent="submitDisable">
                            <InputLabel for="password" value="Confirma tu contrasena" />
                            <TextInput
                                id="password"
                                v-model="disableForm.password"
                                type="password"
                                class="mt-1 block w-full"
                            />
                            <InputError class="mt-2" :message="disableForm.errors.password" />

                            <button
                                type="submit"
                                :disabled="disableForm.processing"
                                class="mt-3 w-full px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                            >
                                Desactivar
                            </button>
                        </form>
                    </div>
                    <div v-else class="text-xs text-gray-500 text-center mt-2">
                        Tu rol requiere autenticacion de dos pasos y no puede ser desactivada.
                    </div>
                </div>
            </div>

            <!-- Back link -->
            <div class="mt-6 text-center">
                <Link :href="route('profile.edit')" class="text-sm text-pink-600 hover:text-pink-800">
                    &larr; Volver al perfil
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
