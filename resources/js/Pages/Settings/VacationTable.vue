<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    vacationTable: Array,
});

const entries = ref(
    props.vacationTable.map(item => ({
        id: item.id,
        years_of_service: item.years_of_service,
        vacation_days: item.vacation_days,
    }))
);

const saving = ref(false);

const addRow = () => {
    const lastEntry = entries.value[entries.value.length - 1];
    entries.value.push({
        id: null,
        years_of_service: lastEntry ? lastEntry.years_of_service + 1 : 1,
        vacation_days: lastEntry ? lastEntry.vacation_days + 2 : 12,
    });
};

const removeRow = (index) => {
    entries.value.splice(index, 1);
};

const save = () => {
    saving.value = true;
    router.put(route('settings.vacation-table.update'), {
        entries: entries.value,
    }, {
        onFinish: () => {
            saving.value = false;
        },
    });
};
</script>

<template>
    <Head title="Tabla de Vacaciones" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Tabla de Vacaciones LFT Mexico
            </h2>
        </template>

        <div class="max-w-3xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('settings.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a configuracion
                </Link>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Tabla de Vacaciones LFT Mexico</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Define los dias de vacaciones correspondientes por anos de servicio segun la Ley Federal del Trabajo.
                        </p>
                    </div>
                    <button
                        @click="addRow"
                        type="button"
                        class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors text-sm"
                    >
                        Agregar Fila
                    </button>
                </div>

                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Anos de Servicio
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Dias de Vacaciones
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Acciones
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="(entry, index) in entries" :key="index" class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <input
                                    v-model.number="entry.years_of_service"
                                    type="number"
                                    min="1"
                                    class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                />
                            </td>
                            <td class="px-6 py-3">
                                <input
                                    v-model.number="entry.vacation_days"
                                    type="number"
                                    min="1"
                                    class="w-32 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                />
                            </td>
                            <td class="px-6 py-3 text-right">
                                <button
                                    @click="removeRow(index)"
                                    type="button"
                                    class="text-red-600 hover:text-red-900 text-sm"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="entries.length === 0">
                            <td colspan="3" class="px-6 py-8 text-center text-gray-500">
                                No hay entradas. Haz clic en "Agregar Fila" para comenzar.
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Actions -->
                <div class="mt-6 flex justify-end space-x-4">
                    <Link
                        :href="route('settings.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        @click="save"
                        :disabled="saving"
                        type="button"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ saving ? 'Guardando...' : 'Guardar Tabla' }}
                    </button>
                </div>
            </div>

            <!-- Info Box -->
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex">
                    <svg class="h-5 w-5 text-blue-400 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                    <div class="text-sm text-blue-700">
                        <p class="font-medium">Referencia LFT Mexico</p>
                        <p class="mt-1">
                            Esta tabla se usa para calcular automaticamente los dias de vacaciones
                            al crear o editar empleados, basandose en su antiguedad.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
