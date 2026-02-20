<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    users: Object,
    roles: Array,
    filters: Object,
    can: Object,
});

const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const twoFactor = ref(props.filters.two_factor || '');
const passwordStatus = ref(props.filters.password_status || '');

const applyFilters = debounce(() => {
    router.get(route('users.index'), {
        search: search.value || undefined,
        role: role.value || undefined,
        two_factor: twoFactor.value || undefined,
        password_status: passwordStatus.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, role, twoFactor, passwordStatus], applyFilters);

const deleteUser = (user) => {
    if (confirm(`¿Estas seguro de eliminar al usuario "${user.name}"? Esta accion no se puede deshacer.`)) {
        router.delete(route('users.destroy', user.id));
    }
};
</script>

<template>
    <AppLayout>
        <Head title="Usuarios" />

        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Usuarios del Sistema</h2>
        </template>

        <div class="bg-white rounded-lg shadow">
            <!-- Toolbar -->
            <div class="p-4 border-b border-gray-200">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="flex flex-col sm:flex-row gap-3 flex-1">
                        <!-- Search -->
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Buscar por nombre o email..."
                            class="rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm w-full sm:w-64"
                        />

                        <!-- Role filter -->
                        <select
                            v-model="role"
                            class="rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        >
                            <option value="">Todos los roles</option>
                            <option v-for="r in roles" :key="r" :value="r">
                                {{ r.charAt(0).toUpperCase() + r.slice(1) }}
                            </option>
                        </select>

                        <!-- 2FA filter -->
                        <select
                            v-model="twoFactor"
                            class="rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        >
                            <option value="">2FA: Todos</option>
                            <option value="enabled">2FA Activo</option>
                            <option value="disabled">2FA Inactivo</option>
                        </select>

                        <!-- Password status filter -->
                        <select
                            v-model="passwordStatus"
                            class="rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        >
                            <option value="">Contraseña: Todos</option>
                            <option value="must_change">Pendiente de cambio</option>
                            <option value="ok">Contraseña establecida</option>
                        </select>
                    </div>

                    <div v-if="can.create">
                        <Link
                            :href="route('users.create')"
                            class="inline-flex items-center px-4 py-2 bg-pink-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-pink-700 transition"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Nuevo Usuario
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuario</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado Vinculado</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">2FA</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contraseña</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                        <span class="text-pink-600 font-semibold text-sm">
                                            {{ user.name.charAt(0).toUpperCase() }}
                                        </span>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900">{{ user.name }}</div>
                                        <div class="text-sm text-gray-500">{{ user.email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span
                                    v-for="r in user.roles"
                                    :key="r.id"
                                    :class="[
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                        r.name === 'admin' ? 'bg-purple-100 text-purple-800' :
                                        r.name === 'rrhh' ? 'bg-blue-100 text-blue-800' :
                                        r.name === 'supervisor' ? 'bg-yellow-100 text-yellow-800' :
                                        'bg-gray-100 text-gray-800'
                                    ]"
                                >
                                    {{ r.name.charAt(0).toUpperCase() + r.name.slice(1) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <span v-if="user.employee">{{ user.employee.full_name }}</span>
                                <span v-else class="text-gray-400 italic">Sin vincular</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="user.two_factor_enabled" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Activo
                                </span>
                                <span v-else-if="user.requires_two_factor" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                    Pendiente
                                </span>
                                <span v-else class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">
                                    No requerido
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="user.must_change_password" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                    Temporal
                                </span>
                                <span v-else class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    Establecida
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <Link
                                    :href="route('users.edit', user.id)"
                                    class="text-pink-600 hover:text-pink-900 mr-3"
                                >
                                    Editar
                                </Link>
                                <button
                                    @click="deleteUser(user)"
                                    class="text-red-600 hover:text-red-900"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="users.data.length === 0">
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                No se encontraron usuarios.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="users.links && users.links.length > 3" class="px-6 py-3 border-t border-gray-200 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    Mostrando {{ users.from }} a {{ users.to }} de {{ users.total }} usuarios
                </div>
                <div class="flex space-x-1">
                    <template v-for="link in users.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active
                                    ? 'bg-pink-600 text-white'
                                    : 'text-gray-600 hover:bg-gray-100'
                            ]"
                            v-html="link.label"
                            preserve-state
                        />
                        <span
                            v-else
                            class="px-3 py-1 rounded text-sm text-gray-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
