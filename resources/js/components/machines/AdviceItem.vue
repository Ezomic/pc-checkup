<script setup lang="ts">
import type { FlagSeverity, SharedAdvice } from '@/types';

defineProps<{
    item: SharedAdvice;
}>();

const dots: Record<FlagSeverity, string> = {
    high: 'bg-red-500',
    medium: 'bg-amber-500',
    low: 'bg-slate-400',
};
</script>

<template>
    <li
        class="flex break-inside-avoid gap-3 rounded-xl border border-border bg-card p-4"
    >
        <span
            class="mt-1.5 size-2.5 shrink-0 rounded-full"
            :class="dots[item.severity]"
        />
        <div>
            <p class="font-medium">{{ item.headline }}</p>
            <p v-if="item.advice" class="text-sm text-muted-foreground">
                {{ item.advice }}
            </p>
        </div>
    </li>
</template>
