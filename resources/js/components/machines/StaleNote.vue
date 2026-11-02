<script setup lang="ts">
import { Clock } from '@lucide/vue';
</script>

<template>
    <span
        class="inline-flex items-center gap-1 font-medium text-amber-800 dark:text-amber-300"
        data-test="stale-note"
    >
        <Clock class="size-3.5" aria-hidden="true" />
        Not checked recently
    </span>
</template>
