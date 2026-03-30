<script setup>
/**
 * SearchableSelect - Searchable dropdown component using Headless UI Combobox.
 *
 * Replaces native <select> elements with a filterable, keyboard-navigable dropdown.
 */
import { ref, computed, watch } from 'vue';
import {
    Combobox,
    ComboboxInput,
    ComboboxButton,
    ComboboxOptions,
    ComboboxOption,
} from '@headlessui/vue';

const props = defineProps({
    /** Currently selected value (v-model). */
    modelValue: [String, Number],
    /** Array of option objects. */
    options: {
        type: Array,
        required: true,
    },
    /** Key in each option used as the value. */
    valueKey: {
        type: String,
        default: 'id',
    },
    /** Key in each option used as primary display label. */
    labelKey: {
        type: String,
        default: 'label',
    },
    /** Optional secondary key shown in parentheses. */
    secondaryKey: {
        type: String,
        default: null,
    },
    /** Placeholder shown when nothing is selected. */
    placeholder: {
        type: String,
        default: 'Buscar...',
    },
    /** Whether the input is disabled. */
    disabled: {
        type: Boolean,
        default: false,
    },
    /** Whether to show error styling. */
    hasError: {
        type: Boolean,
        default: false,
    },
    /** Allow clearing the selection. */
    nullable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');

const selectedOption = computed(() => {
    if (!props.modelValue && props.modelValue !== 0) return null;
    return props.options.find(o => o[props.valueKey] == props.modelValue) || null;
});

const filteredOptions = computed(() => {
    if (!query.value) return props.options;
    const q = query.value.toLowerCase();
    return props.options.filter(o => {
        const label = String(o[props.labelKey] || '').toLowerCase();
        const secondary = props.secondaryKey ? String(o[props.secondaryKey] || '').toLowerCase() : '';
        return label.includes(q) || secondary.includes(q);
    });
});

const displayValue = (option) => {
    if (!option) return '';
    const label = option[props.labelKey] || '';
    if (props.secondaryKey && option[props.secondaryKey]) {
        return `${label} (${option[props.secondaryKey]})`;
    }
    return label;
};

const onSelect = (option) => {
    emit('update:modelValue', option ? option[props.valueKey] : '');
};

const onClear = () => {
    emit('update:modelValue', '');
    query.value = '';
};
</script>

<template>
    <Combobox
        :model-value="selectedOption"
        @update:model-value="onSelect"
        :disabled="disabled"
        :nullable="nullable"
        as="div"
        class="relative"
    >
        <div class="relative">
            <ComboboxInput
                :display-value="displayValue"
                @change="query = $event.target.value"
                :placeholder="placeholder"
                :disabled="disabled"
                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 pr-16 disabled:bg-gray-100"
                :class="{
                    'border-red-500': hasError,
                }"
            />
            <div class="absolute inset-y-0 right-0 flex items-center pr-2 gap-1">
                <button
                    v-if="modelValue && !disabled"
                    type="button"
                    @click="onClear"
                    class="p-1 text-gray-400 hover:text-gray-600"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <ComboboxButton class="p-1 text-gray-400 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                    </svg>
                </ComboboxButton>
            </div>
        </div>

        <ComboboxOptions
            class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-black/5 focus:outline-none text-sm"
        >
            <div
                v-if="filteredOptions.length === 0"
                class="px-4 py-2 text-gray-500"
            >
                No se encontraron resultados.
            </div>
            <ComboboxOption
                v-for="option in filteredOptions"
                :key="option[valueKey]"
                :value="option"
                v-slot="{ active, selected }"
            >
                <li
                    :class="[
                        'relative cursor-pointer select-none py-2 pl-3 pr-9',
                        active ? 'bg-pink-600 text-white' : 'text-gray-900',
                    ]"
                >
                    <span :class="['block truncate', selected ? 'font-semibold' : '']">
                        {{ option[labelKey] }}
                        <span v-if="secondaryKey && option[secondaryKey]" :class="active ? 'text-pink-200' : 'text-gray-500'">
                            ({{ option[secondaryKey] }})
                        </span>
                    </span>
                    <span
                        v-if="selected"
                        :class="[
                            'absolute inset-y-0 right-0 flex items-center pr-3',
                            active ? 'text-white' : 'text-pink-600',
                        ]"
                    >
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </span>
                </li>
            </ComboboxOption>
        </ComboboxOptions>
    </Combobox>
</template>
