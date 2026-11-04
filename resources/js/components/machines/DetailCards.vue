<script setup lang="ts">
import type { DetailCard, DetailRow, DetailTone } from '@/types';

defineProps<{
    cards: DetailCard[];
}>();

const tones: Record<DetailTone, string> = {
    good: 'text-green-700 dark:text-green-400',
    bad: 'text-red-700 dark:text-red-400',
    neutral: '',
};

const LONG_VALUE = 80;

function toneClass(row: DetailRow): string {
    return tones[row.tone];
}

function isLong(row: DetailRow): boolean {
    return row.value.length > LONG_VALUE;
}
</script>

<template>
    <div
        v-if="cards.length > 0"
        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
        data-test="detail-cards"
    >
        <section
            v-for="card in cards"
            :key="card.id"
            class="flex min-w-0 flex-col gap-3 rounded-xl border border-border p-4"
            :data-test="`detail-card-${card.id}`"
        >
            <h2 class="text-lg font-semibold">{{ card.title }}</h2>

            <dl
                v-if="card.rows.length > 0"
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
            >
                <template v-for="row in card.rows" :key="row.label">
                    <template v-if="isLong(row)">
                        <dt
                            class="col-span-2 font-medium break-words"
                            :class="toneClass(row)"
                            :data-tone="row.tone"
                        >
                            {{ row.label }}
                        </dt>
                        <dd
                            class="col-span-2 -mt-1 min-w-0 break-words text-muted-foreground"
                        >
                            {{ row.value }}
                        </dd>
                    </template>
                    <template v-else>
                        <dt class="text-muted-foreground">{{ row.label }}</dt>
                        <dd
                            class="min-w-0 text-right font-medium break-words"
                            :class="toneClass(row)"
                            :data-tone="row.tone"
                        >
                            {{ row.value }}
                        </dd>
                    </template>
                </template>
            </dl>

            <div v-if="card.list" class="print:hidden" data-test="detail-list">
                <h3 class="text-sm font-medium">{{ card.list.title }}</h3>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="item in card.list.items"
                        :key="item.label"
                        class="flex justify-between gap-4"
                    >
                        <span
                            class="min-w-0 break-words text-muted-foreground"
                            >{{ item.label }}</span
                        >
                        <span class="shrink-0">{{ item.value }}</span>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
