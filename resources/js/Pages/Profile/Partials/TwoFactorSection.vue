<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const hasTwoFactor = computed(() => page.props.auth.has_two_factor);
const roles = computed(() => page.props.auth.roles || []);
const requiresTwoFactor = computed(() => {
    return roles.value.some(r => ['admin', 'rrhh', 'supervisor'].includes(r));
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                Autenticacion de dos pasos
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                Agrega una capa adicional de seguridad a tu cuenta usando una aplicacion de autenticacion.
            </p>
        </header>

        <div class="mt-4">
            <!-- Enabled -->
            <div v-if="hasTwoFactor" class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-6 h-6 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <div>
                        <p class="font-medium text-green-800">Activa</p>
                        <p class="text-sm text-green-600">Tu cuenta esta protegida con verificacion de dos pasos.</p>
                    </div>
                </div>
                <Link
                    :href="route('two-factor.setup')"
                    class="px-4 py-2 text-sm text-green-700 border border-green-300 rounded-lg hover:bg-green-100"
                >
                    Administrar
                </Link>
            </div>

            <!-- Not Enabled -->
            <div v-else class="flex items-center justify-between p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-6 h-6 text-yellow-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <div>
                        <p class="font-medium text-yellow-800">No configurada</p>
                        <p class="text-sm text-yellow-600">
                            {{ requiresTwoFactor ? 'Tu rol requiere autenticacion de dos pasos.' : 'Protege tu cuenta con verificacion de dos pasos (opcional).' }}
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('two-factor.setup')"
                    class="px-4 py-2 text-sm text-white bg-pink-600 rounded-lg hover:bg-pink-700"
                >
                    Configurar
                </Link>
            </div>
        </div>
    </section>
</template>
