<script setup lang="ts">
import { computed } from 'vue';
import AdviceItem from '@/components/machines/AdviceItem.vue';
import type { FlagAdvice } from '@/types';

const FIRST = 3;

const props = defineProps<{
    advice: FlagAdvice[];
}>();

const first = computed(() => props.advice.slice(0, FIRST));
const rest = computed(() => props.advice.slice(FIRST));
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul class="flex flex-col gap-2">
            <AdviceItem v-for="item in first" :key="item.raw" :item="item" />
        </ul>

        <details v-if="rest.length > 0" class="text-sm">
            <summary class="cursor-pointer text-muted-foreground">
                {{ rest.length }} more to look at
            </summary>
            <ul class="mt-2 flex flex-col gap-2">
                <AdviceItem v-for="item in rest" :key="item.raw" :item="item" />
            </ul>
        </details>
    </div>
</template>
