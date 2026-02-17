<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    preview: Array,
    errors: Array,
    summary: Object,
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({
    file: null,
});

const hasPreview = computed(() => props.preview && props.preview.length > 0);
const hasErrors = computed(() => props.errors && props.errors.length > 0);
const isUploadState = computed(() => !hasPreview.value && !hasErrors.value && !props.summary);

const onFileChange = (event) => {
    form.file = event.target.files[0] || null;
};

const submitPreview = () => {
    form.post(route('employees.import.preview'), {
        forceFormData: true,
    });
};

const confirmForm = useForm({});

const submitConfirm = () => {
    if (confirm('¿Aplicar todos los cambios detectados?')) {
        confirmForm.post(route('employees.import.confirm'));
    }
};

const totalChanges = computed(() => {
    if (!props.preview) return 0;
    return props.preview.reduce((sum, emp) => sum + emp.changes.length, 0);
});
</script>

<template>
    <Head title="Importar Empleados" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Importar Empleados desde Excel
            </h2>
        </template>

        <div class="max-w-5xl mx-auto">
            <!-- Back link -->
            <div class="mb-4">
                <Link
                    :href="route('employees.index')"
                    class="text-pink-600 hover:text-pink-800 text-sm"
                >
                    &larr; Volver a Empleados
                </Link>
            </div>

            <!-- Flash messages -->
            <div v-if="flash.error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800">
                {{ flash.error }}
            </div>

            <!-- Upload State -->
            <div v-if="isUploadState" class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Subir archivo Excel</h3>
                <p class="text-gray-600 mb-6">
                    Sube un archivo .xlsx o .xls exportado desde el sistema.
                    Los cambios seran previsualizados antes de aplicarse.
                </p>

                <form @submit.prevent="submitPreview" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Archivo Excel
                        </label>
                        <input
                            type="file"
                            accept=".xlsx,.xls"
                            @change="onFileChange"
                            class="block w-full text-sm text-gray-500
                                file:mr-4 file:py-2 file:px-4
                                file:rounded-lg file:border-0
                                file:text-sm file:font-semibold
                                file:bg-pink-50 file:text-pink-700
                                hover:file:bg-pink-100"
                        />
                        <p v-if="form.errors.file" class="mt-1 text-sm text-red-600">
                            {{ form.errors.file }}
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="!form.file || form.processing"
                        class="inline-flex items-center px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ form.processing ? 'Procesando...' : 'Previsualizar Cambios' }}
                    </button>
                </form>
            </div>

            <!-- Preview State -->
            <template v-if="!isUploadState">
                <!-- Summary -->
                <div v-if="summary" class="bg-white rounded-lg shadow p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Resumen</h3>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center p-3 bg-gray-50 rounded-lg">
                            <div class="text-2xl font-bold text-gray-800">{{ summary.total_rows }}</div>
                            <div class="text-sm text-gray-600">Filas procesadas</div>
                        </div>
                        <div class="text-center p-3 bg-blue-50 rounded-lg">
                            <div class="text-2xl font-bold text-blue-800">{{ summary.employees_with_changes }}</div>
                            <div class="text-sm text-blue-600">Empleados con cambios</div>
                        </div>
                        <div class="text-center p-3" :class="summary.error_count > 0 ? 'bg-red-50 rounded-lg' : 'bg-green-50 rounded-lg'">
                            <div class="text-2xl font-bold" :class="summary.error_count > 0 ? 'text-red-800' : 'text-green-800'">
                                {{ summary.error_count }}
                            </div>
                            <div class="text-sm" :class="summary.error_count > 0 ? 'text-red-600' : 'text-green-600'">Errores</div>
                        </div>
                    </div>
                </div>

                <!-- Errors -->
                <div v-if="hasErrors" class="bg-red-50 border border-red-200 rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-red-800 mb-3">Errores encontrados</h3>
                    <ul class="space-y-1 text-sm text-red-700">
                        <li v-for="(err, idx) in errors" :key="idx">
                            <span class="font-medium">Fila {{ err.row }}</span>
                            <span v-if="err.employee_number"> ({{ err.employee_number }})</span>:
                            {{ err.message }}
                        </li>
                    </ul>
                </div>

                <!-- Changes Table -->
                <div v-if="hasPreview" class="bg-white rounded-lg shadow overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">
                            Cambios detectados ({{ totalChanges }})
                        </h3>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Campo</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor Anterior</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor Nuevo</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <template v-for="emp in preview" :key="emp.employee_id">
                                <tr v-for="(change, cidx) in emp.changes" :key="`${emp.employee_id}-${cidx}`" class="hover:bg-gray-50">
                                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                                        <template v-if="cidx === 0">
                                            <span class="font-medium text-gray-900">{{ emp.full_name }}</span>
                                            <br>
                                            <span class="text-gray-500">{{ emp.employee_number }}</span>
                                        </template>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-700">
                                        {{ change.label }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-red-600">
                                        {{ change.old_value }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-green-600 font-medium">
                                        {{ change.new_value }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- No changes message -->
                <div v-if="!hasPreview && summary && summary.employees_with_changes === 0" class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
                    <p class="text-yellow-800">No se detectaron cambios en el archivo. Los datos son identicos a los actuales.</p>
                </div>

                <!-- Action buttons -->
                <div class="flex gap-3">
                    <button
                        v-if="hasPreview"
                        @click="submitConfirm"
                        :disabled="confirmForm.processing"
                        class="inline-flex items-center px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
                    >
                        <svg v-if="confirmForm.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        {{ confirmForm.processing ? 'Aplicando...' : 'Confirmar Cambios' }}
                    </button>
                    <Link
                        :href="route('employees.import')"
                        class="inline-flex items-center px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                    >
                        Cancelar
                    </Link>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
