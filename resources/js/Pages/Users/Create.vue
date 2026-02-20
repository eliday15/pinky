<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    roles: Array,
    employees: Array,
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
    employee_id: '',
});

const showPassword = ref(false);

const generatePassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    form.password = password;
    showPassword.value = true;
};

const copyPassword = () => {
    navigator.clipboard.writeText(form.password);
};

const submit = () => {
    form.post(route('users.store'));
};
</script>

<template>
    <AppLayout>
        <Head title="Crear Usuario" />

        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Crear Usuario</h2>
        </template>

        <div class="max-w-2xl">
            <div class="bg-white rounded-lg shadow p-6">
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
                            autofocus
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

                    <!-- Password -->
                    <div>
                        <InputLabel for="password" value="Contraseña Temporal" />
                        <div class="mt-1 flex gap-2">
                            <div class="relative flex-1">
                                <TextInput
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    class="block w-full"
                                    v-model="form.password"
                                    required
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
                                v-if="form.password && showPassword"
                                type="button"
                                @click="copyPassword"
                                class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                            >
                                Copiar
                            </button>
                        </div>
                        <div v-if="form.password && showPassword" class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded text-sm text-yellow-800">
                            <strong>Contraseña generada:</strong> {{ form.password }}
                            <p class="text-xs mt-1">Copia esta contraseña y compartela con el usuario. No podras verla de nuevo.</p>
                        </div>
                        <InputError class="mt-2" :message="form.errors.password" />
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
                            </option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500">Solo se muestran empleados activos que no estan vinculados a ningun usuario.</p>
                        <InputError class="mt-2" :message="form.errors.employee_id" />
                    </div>

                    <!-- Info box -->
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded text-sm text-blue-700">
                        El usuario debera cambiar su contraseña en el primer inicio de sesion.
                        <span v-if="['admin', 'rrhh', 'supervisor'].includes(form.role)">
                            Ademas, debera configurar la autenticacion de dos factores (2FA).
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
                            Crear Usuario
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
