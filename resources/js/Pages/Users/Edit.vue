<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    editUser: Object,
    roles: Array,
    employees: Array,
    can: Object,
});

const form = useForm({
    name: props.editUser.name,
    email: props.editUser.email,
    role: props.editUser.roles?.[0]?.name || '',
    employee_id: props.editUser.employee?.id || '',
});

const passwordForm = useForm({
    password: '',
});

const showPassword = ref(false);

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    passwordForm.password = password;
    showPassword.value = true;
};

const copyPassword = () => {
    navigator.clipboard.writeText(passwordForm.password);
};

const submit = () => {
    form.put(route('users.update', props.editUser.id));
};

const resetPassword = () => {
    if (!confirm('¿Estas seguro? Se reseteara la contraseña y el 2FA. El usuario debera reconfigurar ambos en su proximo inicio de sesion.')) {
        return;
    }
    passwordForm.post(route('users.reset-password', props.editUser.id), {
        onSuccess: () => {
            passwordForm.reset();
            showPassword.value = false;
        },
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Editar Usuario" />

        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Editar Usuario</h2>
        </template>

        <div class="max-w-2xl space-y-6">
            <!-- User Details -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Datos del Usuario</h3>

                <form @submit.prevent="submit" class="space-y-6">
                    <!-- Name -->
                    <div>
                        <InputLabel for="name" value="Nombre" />
                        <TextInput
                            id="name"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.name"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <!-- Email -->
                    <div>
                        <InputLabel for="email" value="Correo Electronico" />
                        <TextInput
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                            required
                        />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <!-- Role -->
                    <div>
                        <InputLabel for="role" value="Rol" />
                        <select
                            id="role"
                            v-model="form.role"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            required
                        >
                            <option value="" disabled>Seleccionar rol...</option>
                            <option v-for="r in roles" :key="r" :value="r">
                                {{ r.charAt(0).toUpperCase() + r.slice(1) }}
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.role" />
                    </div>

                    <!-- Link Employee -->
                    <div>
                        <InputLabel for="employee_id" value="Vincular a Empleado (Opcional)" />
                        <select
                            id="employee_id"
                            v-model="form.employee_id"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        >
                            <option value="">Sin vincular</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.full_name }}
                                <template v-if="emp.user_id === editUser.id"> (actual)</template>
                            </option>
                        </select>
                        <InputError class="mt-2" :message="form.errors.employee_id" />
                    </div>

                    <!-- Status info -->
                    <div class="flex gap-3">
                        <span
                            :class="[
                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                editUser.must_change_password ? 'bg-orange-100 text-orange-800' : 'bg-green-100 text-green-800'
                            ]"
                        >
                            Contraseña: {{ editUser.must_change_password ? 'Temporal' : 'Establecida' }}
                        </span>
                        <span
                            :class="[
                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                editUser.two_factor_enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'
                            ]"
                        >
                            2FA: {{ editUser.two_factor_enabled ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-3">
                        <Link
                            :href="route('users.index')"
                            class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition"
                        >
                            Cancelar
                        </Link>
                        <PrimaryButton
                            :class="{ 'opacity-25': form.processing }"
                            :disabled="form.processing"
                        >
                            Guardar Cambios
                        </PrimaryButton>
                    </div>
                </form>
            </div>

            <!-- Reset Password Section -->
            <div v-if="can.resetPassword" class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Resetear Contraseña y 2FA</h3>
                <p class="text-sm text-gray-500 mb-4">
                    Genera una nueva contraseña temporal. El usuario debera cambiar su contraseña y reconfigurar la autenticacion de dos factores (2FA) en su proximo inicio de sesion.
                </p>

                <div class="space-y-4">
                    <div>
                        <InputLabel for="reset_password" value="Nueva Contraseña Temporal" />
                        <div class="mt-1 flex gap-2">
                            <div class="relative flex-1">
                                <TextInput
                                    id="reset_password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="block w-full"
                                    v-model="passwordForm.password"
                                />
                            </div>
                            <button
                                type="button"
                                @click="generatePassword"
                                class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Generar
                            </button>
                            <button
                                v-if="passwordForm.password && showPassword"
                                type="button"
                                @click="copyPassword"
                                class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Copiar
                            </button>
                        </div>
                        <div v-if="passwordForm.password && showPassword" class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                            <strong>Contraseña generada:</strong> {{ passwordForm.password }}
                        </div>
                        <InputError class="mt-2" :message="passwordForm.errors.password" />
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="button"
                            @click="resetPassword"
                            :disabled="!passwordForm.password || passwordForm.processing"
                            :class="[
                                'inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest transition',
                                !passwordForm.password || passwordForm.processing
                                    ? 'bg-orange-300 cursor-not-allowed'
                                    : 'bg-orange-600 hover:bg-orange-700'
                            ]"
                        >
                            Resetear Contraseña y 2FA
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
