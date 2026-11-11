<script setup lang="ts">
import { computed } from 'vue';
import type { DetailTone, VerdictCard } from '@/types';

const props = defineProps<{
    card: VerdictCard;
}>();

const boxes: Record<DetailTone, string> = {
    bad: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40',
    good: 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/40',
    neutral: 'border-border',
};

const first = computed(() => props.card.rows[0]);
const others = computed(() => props.card.rows.slice(1));
</script>

<template>
    <section
        v-if="first"
        class="flex flex-col gap-2 rounded-xl border p-4"
        :class="boxes[first.tone]"
        :data-tone="first.tone"
        aria-labelledby="worth-keeping-title"
        data-test="worth-keeping"
    >
        <h2
            id="worth-keeping-title"
            class="text-sm font-medium text-muted-foreground"
        >
            {{ card.title }}
        </h2>
        <p
            class="text-xl font-semibold break-words"
            data-test="worth-keeping-verdict"
        >
            {{ first.value }}
        </p>
        <ul v-if="others.length > 0" class="flex flex-col gap-1 text-sm">
            <li
                v-for="row in others"
                :key="row.label"
                class="break-words"
                data-test="worth-keeping-line"
            >
                <span class="font-medium">{{ row.label }}.</span>
                {{ ' ' }}
                <span class="text-muted-foreground">{{ row.value }}</span>
            </li>
        </ul>
    </section>
</template>
