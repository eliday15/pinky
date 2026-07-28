<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    from: String,
    to: String,
    employees: Array,
    departments: Array,
    filters: Object,
    markedCount: Number,
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// Local filter + range state
const search = ref(props.filters.search || '');
const departmentFilter = ref(props.filters.department_id || '');
const fromInput = ref(props.from);
const toInput = ref(props.to);

// Track previous range to distinguish range-vs-filter navigation.
const prevRange = ref(`${props.from}|${props.to}`);

// Marked employees — a Set of IDs. Kept in memory across filter changes so no
// hidden mark is lost when the user filters.
const marked = ref(new Set(
    props.employees.filter(e => e.on_delivery).map(e => e.id)
));

// Keep the date inputs in sync when Inertia delivers new props.
watch(() => [props.from, props.to], ([f, t]) => {
    fromInput.value = f;
    toInput.value = t;
});

// Sync the marked set when the employees prop changes (filter OR range nav).
watch(() => props.employees, (newEmployees) => {
    const newOnDelivery = new Set(newEmployees.filter(e => e.on_delivery).map(e => e.id));
    const currentRange = `${props.from}|${props.to}`;

    if (currentRange !== prevRange.value) {
        // Range changed — reinit from the fresh data of the new range.
        marked.value = new Set(newOnDelivery);
        prevRange.value = currentRange;
    } else {
        // Same range, only filters changed — MERGE to preserve hidden marks.
        const merged = new Set(marked.value);
        for (const id of newOnDelivery) merged.add(id);
        marked.value = merged;
    }
});

// Navigate to a new range (keeps current filters).
const changeRange = () => {
    router.get(route('deliveries.index'), {
        from: fromInput.value || undefined,
        to: toInput.value || undefined,
        search: search.value || undefined,
        department_id: departmentFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
};

// Navigate with the current range when filters change.
const applyFilters = debounce(() => {
    router.get(route('deliveries.index'), {
        from: props.from,
        to: props.to,
        search: search.value || undefined,
        department_id: departmentFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, applyFilters);
watch(departmentFilter, applyFilters);

const toggle = (id) => {
    const next = new Set(marked.value);
    next.has(id) ? next.delete(id) : next.add(id);
    marked.value = next;
};

// Save — sends ALL marked IDs (not just currently visible rows) for this range.
const saving = ref(false);
const save = () => {
    saving.value = true;
    router.post(route('deliveries.store'), {
        start_date: props.from,
        end_date: props.to,
        employee_ids: [...marked.value],
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
};

// 'YYYY-MM-DD' → 'dd/mm/yyyy' without external libs.
const formatDateMX = (dateStr) => {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-');
    return `${d}/${m}/${y}`;
};
</script>

<template>
    <Head title="Personal de entregas" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Personal de entregas
            </h2>
        </template>

        <!-- Page heading -->
        <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Personal de entregas</h1>
                <p class="mt-1 text-sm text-gray-600 max-w-2xl">
                    Elige de qué fecha a qué fecha y marca a quiénes salieron a entregas. A los marcados, su
                    <strong>velada y tiempo extra autorizados</strong> se pagan y reflejan completos esas fechas,
                    porque andan en la calle repartiendo y su checada no los alcanza a registrar.
                    Como se van turnando, lo marcas por el rango que necesites.
                </p>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="text-sm text-gray-600 bg-gray-100 px-3 py-1.5 rounded-full font-medium">
                    {{ marked.size }} marcados
                </span>
                <button
                    type="button"
                    @click="save"
                    :disabled="saving"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
                >
                    {{ saving ? 'Guardando...' : 'Guardar' }}
                </button>
            </div>
        </div>

        <!-- Flash messages -->
        <div v-if="flash.success" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
            {{ flash.success }}
        </div>
        <div v-if="flash.error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm">
            {{ flash.error }}
        </div>

        <!-- Range selector + filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- From -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                    <input
                        v-model="fromInput"
                        type="date"
                        :max="toInput"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="changeRange"
                    />
                </div>

                <!-- To -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                    <input
                        v-model="toInput"
                        type="date"
                        :min="fromInput"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="changeRange"
                    />
                </div>

                <!-- Name search -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar colaborador</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>

                <!-- Department filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Departamento
                        <span class="text-xs text-gray-400 font-normal ml-1">(sugerido: Almacén PT)</span>
                    </label>
                    <select
                        v-model="departmentFilter"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>
            </div>
            <p class="mt-2 text-xs text-gray-500">
                Del {{ formatDateMX(from) }} al {{ formatDateMX(to) }}
            </p>
        </div>

        <!-- Employee table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Colaborador
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Salió a entregas
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ emp.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ emp.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ emp.employee_number }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ emp.department || '—' }}
                        </td>
                        <td class="px-6 py-4 text-center">
                            <input
                                type="checkbox"
                                :checked="marked.has(emp.id)"
                                @change="toggle(emp.id)"
                                class="w-5 h-5 rounded border-gray-300 text-pink-600 focus:ring-pink-500 cursor-pointer"
                            />
                        </td>
                    </tr>

                    <tr v-if="employees.length === 0">
                        <td colspan="3" class="px-6 py-12 text-center text-gray-500">
                            No hay colaboradores que coincidan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
