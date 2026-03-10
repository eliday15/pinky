<script setup>
/**
 * Global form error banner component.
 * Displays all validation errors from an Inertia useForm errors object
 * in a prominent banner at the top of a form.
 */
defineProps({
    errors: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <div v-if="Object.keys(errors).length" class="bg-red-50 border border-red-300 rounded-lg p-4">
        <div class="flex items-start">
            <svg class="w-5 h-5 text-red-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
            </svg>
            <div>
                <h4 class="text-sm font-semibold text-red-800">Se encontraron errores en el formulario:</h4>
                <ul class="mt-2 list-disc list-inside text-sm text-red-700 space-y-1">
                    <li v-for="(error, field) in errors" :key="field">{{ error }}</li>
                </ul>
            </div>
        </div>
    </div>
</template>
