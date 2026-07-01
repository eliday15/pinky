<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    code: '',
    description: '',
    default_break_minutes: null,
    cena_min_overtime_hours: null,
    weekend_overtime_after_hours: null,
    velada_start: null,
    velada_end: null,
});

const submit = () => {
    form.post(route('departments.store'));
};
</script>

<template>
    <Head title="Nuevo Departamento" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Departamento
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('departments.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a departamentos
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <!-- Department Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion del Departamento</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Nombre *
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.name }"
                                placeholder="Ej: Recursos Humanos"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo *
                            </label>
                            <input
                                v-model="form.code"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.code }"
                                placeholder="Ej: RH"
                            />
                            <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                                {{ form.errors.code }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Minutos de Comida por Defecto
                            </label>
                            <input
                                v-model="form.default_break_minutes"
                                type="number"
                                min="0"
                                max="480"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.default_break_minutes }"
                                placeholder="Ej: 60"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Se usa como fallback cuando el horario no define tiempo de comida
                            </p>
                            <p v-if="form.errors.default_break_minutes" class="mt-1 text-sm text-red-600">
                                {{ form.errors.default_break_minutes }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Cena por horas extra
                            </label>
                            <input
                                v-model="form.cena_min_overtime_hours"
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.cena_min_overtime_hours }"
                                placeholder="Ej: 2.5"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Horas extra a partir de las cuales se ofrece una cena al cargar desde checadas. Vacio = no aplica.
                            </p>
                            <p v-if="form.errors.cena_min_overtime_hours" class="mt-1 text-sm text-red-600">
                                {{ form.errors.cena_min_overtime_hours }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tiempo extra en fin de semana
                            </label>
                            <input
                                v-model="form.weekend_overtime_after_hours"
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.weekend_overtime_after_hours }"
                                placeholder="Ej: 7"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                En fin de semana, las horas que excedan de este valor se pagan como tiempo extra (adicional al fin de semana). Vacio = no aplica.
                            </p>
                            <p v-if="form.errors.weekend_overtime_after_hours" class="mt-1 text-sm text-red-600">
                                {{ form.errors.weekend_overtime_after_hours }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Velada: inicio
                            </label>
                            <input
                                v-model="form.velada_start"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.velada_start }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Franja de velada del depto. Vacio = ventana global (22:00).
                            </p>
                            <p v-if="form.errors.velada_start" class="mt-1 text-sm text-red-600">
                                {{ form.errors.velada_start }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Velada: fin
                            </label>
                            <input
                                v-model="form.velada_end"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.velada_end }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Llena ambos o ninguno. Ej. BIES: 15:30 a 22:30.
                            </p>
                            <p v-if="form.errors.velada_end" class="mt-1 text-sm text-red-600">
                                {{ form.errors.velada_end }}
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Descripcion
                            </label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.description }"
                                placeholder="Descripcion del departamento..."
                            />
                            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('departments.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Departamento' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
