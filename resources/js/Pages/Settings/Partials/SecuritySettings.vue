<script setup>
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import QRCode from 'qrcode';

const props = defineProps({
    security: Object,
});

const flash = computed(() => usePage().props.flash || {});

// ─── Password Change ───
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const passwordSuccess = ref(false);

const updatePassword = () => {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            passwordForm.reset();
            passwordSuccess.value = true;
            setTimeout(() => { passwordSuccess.value = false; }, 3000);
        },
    });
};

// ─── 2FA Devices ───
const showAddDevice = ref(false);
const qrCodeDataUrl = ref('');
const showSecret = ref(false);
const copiedCodes = ref(false);
const deleteDeviceId = ref(null);

const addDeviceForm = useForm({
    name: '',
});

const confirmForm = useForm({
    code: '',
    device_id: null,
});

const deleteForm = useForm({
    password: '',
});

const regenerateForm = useForm({});

// Pending device from flash (after store)
const pendingDevice = computed(() => flash.value?.pendingDevice || null);

// Recovery codes from flash (after first device confirmation)
const recoveryCodes = computed(() => flash.value?.recoveryCodes || []);

// Generate QR code image when pending device is available
watch(pendingDevice, async (device) => {
    if (device?.qrCodeUri) {
        try {
            qrCodeDataUrl.value = await QRCode.toDataURL(device.qrCodeUri, {
                width: 256,
                margin: 2,
                color: { dark: '#000000', light: '#ffffff' },
            });
            confirmForm.device_id = device.id;
            showAddDevice.value = true;
        } catch (err) {
            console.error('Error generating QR code:', err);
        }
    }
}, { immediate: true });

const submitAddDevice = () => {
    addDeviceForm.post(route('settings.security.devices.store'), {
        preserveScroll: true,
        onSuccess: () => {
            addDeviceForm.reset();
        },
    });
};

const submitConfirmDevice = () => {
    confirmForm.post(route('settings.security.devices.confirm'), {
        preserveScroll: true,
        onError: () => {
            confirmForm.code = '';
        },
        onSuccess: () => {
            confirmForm.reset();
            showAddDevice.value = false;
            qrCodeDataUrl.value = '';
            showSecret.value = false;
        },
    });
};

const cancelAddDevice = () => {
    showAddDevice.value = false;
    qrCodeDataUrl.value = '';
    showSecret.value = false;
    addDeviceForm.reset();
    confirmForm.reset();
};

const openDeleteModal = (deviceId) => {
    deleteDeviceId.value = deviceId;
    deleteForm.reset();
};

const submitDeleteDevice = () => {
    deleteForm.delete(route('settings.security.devices.destroy', deleteDeviceId.value), {
        preserveScroll: true,
        onSuccess: () => {
            deleteDeviceId.value = null;
            deleteForm.reset();
        },
    });
};

const submitRegenerate = () => {
    if (confirm('Los codigos anteriores dejaran de funcionar. ¿Continuar?')) {
        regenerateForm.post(route('settings.security.recovery-codes.regenerate'), {
            preserveScroll: true,
        });
    }
};

const copyRecoveryCodes = () => {
    const text = recoveryCodes.value.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        copiedCodes.value = true;
        setTimeout(() => { copiedCodes.value = false; }, 2000);
    });
};

const formatDate = (dateStr) => {
    if (!dateStr) return 'Nunca';
    return new Date(dateStr).toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <div class="space-y-6">
        <!-- Password Change Section -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-1">Cambiar Contrasena</h3>
            <p class="text-sm text-gray-500 mb-6">Asegurate de usar una contrasena segura y unica.</p>

            <form @submit.prevent="updatePassword" class="space-y-4 max-w-md">
                <FormErrorBanner :errors="passwordForm.errors" />

                <div>
                    <InputLabel for="current_password" value="Contrasena actual" />
                    <TextInput
                        id="current_password"
                        v-model="passwordForm.current_password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="current-password"
                    />
                    <InputError :message="passwordForm.errors.current_password" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="new_password" value="Nueva contrasena" />
                    <TextInput
                        id="new_password"
                        v-model="passwordForm.password"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />
                    <InputError :message="passwordForm.errors.password" class="mt-2" />
                </div>

                <div>
                    <InputLabel for="password_confirmation" value="Confirmar contrasena" />
                    <TextInput
                        id="password_confirmation"
                        v-model="passwordForm.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        autocomplete="new-password"
                    />
                    <InputError :message="passwordForm.errors.password_confirmation" class="mt-2" />
                </div>

                <div class="flex items-center gap-4">
                    <PrimaryButton :disabled="passwordForm.processing">
                        {{ passwordForm.processing ? 'Guardando...' : 'Cambiar Contrasena' }}
                    </PrimaryButton>
                    <span v-if="passwordSuccess" class="text-sm text-green-600">Contrasena actualizada.</span>
                </div>
            </form>
        </div>

        <!-- 2FA Devices Section -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-lg font-semibold text-gray-800">Autenticadores (2FA)</h3>
                <span
                    v-if="security.twoFactorEnabled"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
                >
                    Activo
                </span>
                <span
                    v-else
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600"
                >
                    No configurado
                </span>
            </div>
            <p class="text-sm text-gray-500 mb-6">
                Agrega multiples dispositivos de autenticacion para mayor seguridad.
            </p>

            <!-- Device List -->
            <div v-if="security.devices.length > 0" class="space-y-3 mb-6">
                <div
                    v-for="device in security.devices"
                    :key="device.id"
                    class="flex items-center justify-between p-4 border border-gray-200 rounded-lg"
                >
                    <div class="flex items-center">
                        <svg class="w-8 h-8 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-gray-800">{{ device.name }}</p>
                            <p class="text-xs text-gray-500">
                                Agregado: {{ formatDate(device.confirmed_at) }}
                                <span v-if="device.last_used_at" class="ml-2">
                                    · Ultimo uso: {{ formatDate(device.last_used_at) }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <button
                        @click="openDeleteModal(device.id)"
                        class="text-sm text-red-600 hover:text-red-800 font-medium"
                    >
                        Eliminar
                    </button>
                </div>
            </div>

            <!-- No devices message -->
            <div v-else-if="!pendingDevice" class="text-center py-8 mb-6 border border-dashed border-gray-300 rounded-lg">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                <p class="mt-2 text-sm text-gray-600">No tienes autenticadores configurados.</p>
                <p class="text-xs text-gray-500">Agrega un dispositivo para proteger tu cuenta.</p>
            </div>

            <!-- Add Device Flow -->
            <div v-if="!pendingDevice && !showAddDevice">
                <button
                    @click="showAddDevice = true"
                    class="inline-flex items-center px-4 py-2 bg-pink-600 text-white text-sm font-medium rounded-lg hover:bg-pink-700 transition-colors"
                >
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Agregar autenticador
                </button>
            </div>

            <!-- Step 1: Name the device -->
            <div v-if="showAddDevice && !pendingDevice" class="border border-gray-200 rounded-lg p-4">
                <h4 class="text-sm font-medium text-gray-800 mb-3">Nuevo autenticador</h4>
                <form @submit.prevent="submitAddDevice" class="space-y-3">
                    <div>
                        <InputLabel for="device_name" value="Nombre del dispositivo" />
                        <TextInput
                            id="device_name"
                            v-model="addDeviceForm.name"
                            type="text"
                            class="mt-1 block w-full max-w-sm"
                            placeholder="Ej: Google Authenticator - iPhone"
                            autofocus
                        />
                        <InputError :message="addDeviceForm.errors.name" class="mt-2" />
                    </div>
                    <div class="flex gap-3">
                        <PrimaryButton :disabled="addDeviceForm.processing">
                            {{ addDeviceForm.processing ? 'Creando...' : 'Continuar' }}
                        </PrimaryButton>
                        <button
                            type="button"
                            @click="cancelAddDevice"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 2: QR Code + Confirm -->
            <div v-if="pendingDevice" class="border border-gray-200 rounded-lg p-6 space-y-5">
                <div>
                    <h4 class="text-sm font-medium text-gray-800 mb-1">Configurar: {{ pendingDevice.name }}</h4>
                    <p class="text-xs text-gray-500">
                        Escanea el codigo QR con tu aplicacion de autenticacion y luego ingresa el codigo de 6 digitos.
                    </p>
                </div>

                <!-- QR Code -->
                <div class="flex justify-center">
                    <div class="bg-white p-3 border-2 border-gray-200 rounded-lg">
                        <img v-if="qrCodeDataUrl" :src="qrCodeDataUrl" alt="QR Code" class="w-48 h-48" />
                        <div v-else class="w-48 h-48 flex items-center justify-center text-gray-400">
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
                        {{ showSecret ? 'Ocultar clave secreta' : 'Ingresa la clave manualmente' }}
                    </button>
                    <div v-if="showSecret" class="mt-2 p-3 bg-gray-100 rounded-lg">
                        <p class="text-xs text-gray-500 mb-1">Clave secreta:</p>
                        <code class="text-sm font-mono font-bold text-gray-800 select-all">{{ pendingDevice.secret }}</code>
                    </div>
                </div>

                <!-- Confirm Code -->
                <form @submit.prevent="submitConfirmDevice" class="max-w-sm mx-auto">
                    <FormErrorBanner :errors="confirmForm.errors" />

                    <InputLabel for="confirm_code" value="Codigo de verificacion" />
                    <TextInput
                        id="confirm_code"
                        v-model="confirmForm.code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        class="mt-1 block w-full text-center text-2xl tracking-widest"
                        placeholder="000000"
                    />
                    <InputError class="mt-2" :message="confirmForm.errors.code" />

                    <div class="flex gap-3 mt-4">
                        <PrimaryButton class="flex-1 justify-center" :disabled="confirmForm.processing">
                            {{ confirmForm.processing ? 'Verificando...' : 'Confirmar' }}
                        </PrimaryButton>
                        <button
                            type="button"
                            @click="cancelAddDevice"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-lg"
                        >
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>

            <!-- Delete Device Modal -->
            <div v-if="deleteDeviceId !== null" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-sm mx-4">
                    <h4 class="text-lg font-semibold text-gray-800 mb-2">Eliminar autenticador</h4>
                    <p class="text-sm text-gray-600 mb-4">Ingresa tu contrasena para confirmar la eliminacion.</p>

                    <form @submit.prevent="submitDeleteDevice">
                        <FormErrorBanner :errors="deleteForm.errors" />

                        <InputLabel for="delete_password" value="Contrasena" />
                        <TextInput
                            id="delete_password"
                            v-model="deleteForm.password"
                            type="password"
                            class="mt-1 block w-full"
                            autofocus
                        />
                        <InputError :message="deleteForm.errors.password" class="mt-2" />

                        <div class="flex gap-3 mt-4">
                            <button
                                type="submit"
                                :disabled="deleteForm.processing"
                                class="flex-1 px-4 py-2 text-sm bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                            >
                                {{ deleteForm.processing ? 'Eliminando...' : 'Eliminar' }}
                            </button>
                            <button
                                type="button"
                                @click="deleteDeviceId = null; deleteForm.reset()"
                                class="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recovery Codes (shown after first device confirmation) -->
        <div v-if="recoveryCodes.length > 0" class="bg-white rounded-lg shadow p-6">
            <div class="flex items-start mb-4">
                <svg class="w-6 h-6 text-yellow-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Codigos de recuperacion</h3>
                    <p class="text-sm text-gray-600 mt-1">
                        Guarda estos codigos en un lugar seguro. Cada codigo solo puede usarse una vez.
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

        <!-- Recovery Codes Management (when 2FA is enabled) -->
        <div v-if="security.twoFactorEnabled && recoveryCodes.length === 0" class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-1">Codigos de recuperacion</h3>
            <p class="text-sm text-gray-500 mb-4">
                Los codigos de recuperacion te permiten acceder a tu cuenta si pierdes acceso a tus autenticadores.
            </p>

            <div class="flex items-center justify-between mb-4">
                <span class="text-sm text-gray-600">Codigos restantes:</span>
                <span
                    class="text-sm font-semibold"
                    :class="security.recoveryCodesCount <= 2 ? 'text-red-600' : 'text-gray-800'"
                >
                    {{ security.recoveryCodesCount }}
                </span>
            </div>

            <button
                @click="submitRegenerate"
                :disabled="regenerateForm.processing"
                class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 transition"
            >
                {{ regenerateForm.processing ? 'Regenerando...' : 'Regenerar codigos de recuperacion' }}
            </button>
        </div>
    </div>
</template>
