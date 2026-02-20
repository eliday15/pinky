<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    settings: Array,
    can: Object,
});

const form = useForm({
    settings: props.settings.map(s => ({
        key: s.key,
        value: s.value,
    })),
});

const submit = () => {
    form.put(route('settings.update'));
};

/**
 * Get HTML input type based on setting type.
 *
 * Args:
 *     type: Setting type string (integer, float, boolean, text)
 *
 * Returns:
 *     Corresponding HTML input type string
 */
const getInputType = (type) => {
    switch (type) {
        case 'integer':
            return 'number';
        case 'float':
            return 'number';
        case 'boolean':
            return 'checkbox';
        default:
            return 'text';
    }
};

const getInputStep = (type) => {
    return type === 'float' ? '0.01' : '1';
};

const updateValue = (index, setting, event) => {
    if (setting.type === 'boolean') {
        form.settings[index].value = event.target.checked ? 'true' : 'false';
    } else {
        form.settings[index].value = event.target.value;
    }
};
</script>

<template>
    <Head title="Configuracion - General" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Configuracion General
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6">
            <Link :href="route('settings.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a configuracion
            </Link>
        </div>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="space-y-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-6">General</h3>

                    <div class="space-y-6">
                        <div
                            v-for="(setting, index) in settings"
                            :key="setting.key"
                            class="border-b border-gray-200 pb-6 last:border-0 last:pb-0"
                        >
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <label :for="setting.key" class="block text-sm font-medium text-gray-700">
                                        {{ setting.label }}
                                    </label>
                                    <p v-if="setting.description" class="mt-1 text-sm text-gray-500">
                                        {{ setting.description }}
                                    </p>
                                </div>
                                <div class="ml-4 w-48">
                                    <!-- Boolean Input -->
                                    <template v-if="setting.type === 'boolean'">
                                        <label class="inline-flex items-center">
                                            <input
                                                :id="setting.key"
                                                type="checkbox"
                                                :checked="form.settings[index]?.value === 'true'"
                                                @change="updateValue(index, setting, $event)"
                                                :disabled="!can.edit"
                                                class="rounded border-gray-300 text-pink-600 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                            />
                                            <span class="ml-2 text-sm text-gray-600">
                                                {{ form.settings[index]?.value === 'true' ? 'Si' : 'No' }}
                                            </span>
                                        </label>
                                    </template>

                                    <!-- Number/Text Input -->
                                    <template v-else>
                                        <input
                                            :id="setting.key"
                                            :type="getInputType(setting.type)"
                                            :step="getInputStep(setting.type)"
                                            :value="form.settings[index]?.value"
                                            @input="updateValue(index, setting, $event)"
                                            :disabled="!can.edit"
                                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 disabled:bg-gray-100"
                                        />
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="settings.length === 0" class="text-center py-8 text-gray-500">
                        No hay configuraciones generales disponibles.
                    </div>
                </div>

                <!-- Actions -->
                <div v-if="can.edit && settings.length > 0" class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Cambios' }}
                    </button>
                </div>
            </form>

            <!-- Info Box -->
            <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div class="flex">
                    <svg class="h-5 w-5 text-blue-400 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                    <div class="text-sm text-blue-700">
                        <p class="font-medium">Nota sobre los cambios</p>
                        <p class="mt-1">
                            Los cambios en la configuracion general afectan el comportamiento del sistema.
                            Revise cuidadosamente antes de guardar.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
