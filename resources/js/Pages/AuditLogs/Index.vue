<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { formatDateTime } from '@/utils/date';

const props = defineProps({
    logs: Object,
    users: Array,
    employees: Array,
    filters: Object,
    modules: Array,
    actions: Array,
    contexts: Array,
    entities: Array,
    roles: Array,
});

const filters = ref({
    search: props.filters.search || '',
    module: props.filters.module || '',
    action: props.filters.action || '',
    user_id: props.filters.user_id || '',
    employee_id: props.filters.employee_id || '',
    entity: props.filters.entity || '',
    context: props.filters.context || '',
    actor_role: props.filters.actor_role || '',
    from_date: props.filters.from_date || '',
    to_date: props.filters.to_date || '',
});

const applyFilters = () => {
    // Drop empty values so the URL stays readable and shareable.
    const active = Object.fromEntries(
        Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null),
    );

    router.get(route('audit-logs.index'), active, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

let searchTimer = null;
watch(() => filters.value.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 400);
});

const clearFilters = () => {
    Object.keys(filters.value).forEach((key) => {
        filters.value[key] = '';
    });
    applyFilters();
};

const activeFilterCount = computed(
    () => Object.entries(filters.value).filter(([, value]) => value !== '' && value !== null).length,
);

const actionColors = {
    create: 'bg-green-100 text-green-800',
    update: 'bg-blue-100 text-blue-800',
    delete: 'bg-red-100 text-red-800',
    approve: 'bg-green-100 text-green-800',
    reject: 'bg-red-100 text-red-800',
    cancel: 'bg-red-100 text-red-800',
    recalculate: 'bg-blue-100 text-blue-800',
    close: 'bg-emerald-100 text-emerald-800',
    reopen: 'bg-amber-100 text-amber-800',
    pay: 'bg-emerald-100 text-emerald-800',
    import: 'bg-purple-100 text-purple-800',
    resolve: 'bg-amber-100 text-amber-800',
    login: 'bg-indigo-100 text-indigo-800',
    logout: 'bg-gray-100 text-gray-800',
    login_failed: 'bg-red-100 text-red-800',
    sync: 'bg-purple-100 text-purple-800',
    export: 'bg-yellow-100 text-yellow-800',
};

const labelFor = (options, value) => options.find((o) => o.value === value)?.label || value;

const initials = (name) => (name || '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();
</script>

<template>
    <Head title="Auditoria" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Logs de Auditoria
            </h2>
        </template>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="Descripcion, empleado o usuario..."
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Quien lo hizo</label>
                    <select
                        v-model="filters.user_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los usuarios</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol de quien lo hizo</label>
                    <select
                        v-model="filters.actor_role"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los roles</option>
                        <option v-for="r in roles" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empleado afectado</label>
                    <select
                        v-model="filters.employee_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los empleados</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">
                            {{ e.employee_number }} - {{ e.full_name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Accion</label>
                    <select
                        v-model="filters.action"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todas las acciones</option>
                        <option v-for="a in actions" :key="a.value" :value="a.value">{{ a.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Modulo</label>
                    <select
                        v-model="filters.module"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los modulos</option>
                        <option v-for="m in modules" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de registro</label>
                    <select
                        v-model="filters.entity"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los tipos</option>
                        <option v-for="e in entities" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Origen</label>
                    <select
                        v-model="filters.context"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        @change="applyFilters"
                    >
                        <option value="">Todos los origenes</option>
                        <option v-for="c in contexts" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                        <input
                            v-model="filters.from_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                            @change="applyFilters"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                        <input
                            v-model="filters.to_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                            @change="applyFilters"
                        />
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100">
                <p class="text-sm text-gray-500">
                    {{ logs.total }} evento{{ logs.total === 1 ? '' : 's' }}
                    <span v-if="activeFilterCount"> con {{ activeFilterCount }} filtro{{ activeFilterCount === 1 ? '' : 's' }} aplicado{{ activeFilterCount === 1 ? '' : 's' }}</span>
                </p>
                <button
                    v-if="activeFilterCount"
                    @click="clearFilters"
                    class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm"
                >
                    Limpiar filtros
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha y hora</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quien lo hizo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Que hizo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado afectado</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origen</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50 align-top">
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ formatDateTime(log.created_at) }}
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-pink-100 text-xs font-semibold text-pink-700">
                                    {{ initials(log.actor_label) }}
                                </span>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">{{ log.actor_label }}</div>
                                    <div v-if="log.actor_role" class="text-xs text-gray-500">{{ log.actor_role }}</div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-4 text-sm text-gray-700 max-w-md">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span :class="[actionColors[log.action] || 'bg-gray-100 text-gray-800', 'px-2 py-0.5 text-xs rounded-full font-medium']">
                                    {{ labelFor(actions, log.action) }}
                                </span>
                                <span class="text-xs text-gray-500">{{ labelFor(modules, log.module) }}</span>
                            </div>
                            <div class="text-gray-900">{{ log.summary }}</div>
                        </td>

                        <td class="px-4 py-4 text-sm text-gray-700">
                            <span v-if="log.employee">
                                {{ log.employee.full_name }}
                                <span class="block text-xs text-gray-500">{{ log.employee.employee_number }}</span>
                            </span>
                            <span v-else class="text-gray-400">-</span>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ log.context_label }}
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium">
                            <Link :href="route('audit-logs.show', log.id)" class="text-pink-600 hover:text-pink-900">
                                Ver
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron logs de auditoria con estos filtros
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="logs.links.length > 3" class="px-6 py-3 border-t border-gray-200">
                <nav class="flex items-center justify-between">
                    <div class="text-sm text-gray-700">
                        Mostrando {{ logs.from }} a {{ logs.to }} de {{ logs.total }}
                    </div>
                    <div class="flex space-x-1">
                        <template v-for="link in logs.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                v-html="link.label"
                                :class="[
                                    'px-3 py-1 rounded text-sm',
                                    link.active
                                        ? 'bg-pink-600 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]"
                            />
                            <span
                                v-else
                                v-html="link.label"
                                class="px-3 py-1 rounded text-sm bg-gray-100 text-gray-400"
                            />
                        </template>
                    </div>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
