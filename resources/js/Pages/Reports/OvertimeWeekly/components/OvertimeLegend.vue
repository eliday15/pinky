<script setup>
import { inject, ref } from 'vue';
const showPending = inject('showPending', ref(true));
</script>

<template>
    <div class="px-4 pt-3 pb-2 text-xs text-gray-600 flex flex-wrap items-center gap-3">
        <span class="inline-flex items-center gap-1">
            <span class="inline-block w-3 h-3 rounded bg-emerald-100 border border-emerald-300"></span>
            <span><strong>1.5 ✓</strong> = autorizado y aprobado</span>
        </span>
        <span v-if="showPending" class="inline-flex items-center gap-1">
            <span class="inline-block w-3 h-3 rounded bg-amber-100 border border-amber-300"></span>
            <span><strong>+0.5</strong> = trabajado y detectado, sin aprobar</span>
        </span>
    </div>
</template>
