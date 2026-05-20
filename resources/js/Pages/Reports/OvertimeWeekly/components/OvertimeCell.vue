<script setup>
import { computed } from 'vue';
import { formatHours } from '../format';

const props = defineProps({
    approved: { type: Number, default: 0 },
    pending: { type: Number, default: 0 },
    showZero: { type: Boolean, default: true },
});

const hasApproved = computed(() => props.approved > 0);
const hasPending = computed(() => props.pending > 0);
</script>

<template>
    <div class="leading-tight">
        <div v-if="hasApproved" class="text-emerald-700 font-medium">
            {{ formatHours(approved) }} <span aria-label="aprobado">✓</span>
        </div>
        <div v-if="hasPending" class="text-amber-700 text-xs">
            +{{ formatHours(pending) }}
        </div>
        <div v-if="!hasApproved && !hasPending && showZero" class="text-gray-300">0</div>
    </div>
</template>
