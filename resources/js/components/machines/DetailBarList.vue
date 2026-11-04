<script setup lang="ts">
import type { DetailBar } from '@/types';

defineProps<{
    items: DetailBar[];
    small?: boolean;
}>();
</script>

<template>
    <ul
        class="flex flex-col text-sm"
        :class="small ? 'gap-2 text-xs' : 'gap-3'"
    >
        <li v-for="item in items" :key="item.label">
            <div class="flex justify-between gap-4">
                <span class="min-w-0 break-words">{{ item.label }}</span>
                <span class="shrink-0 font-medium">{{ item.value }}</span>
            </div>
            <div
                class="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                role="presentation"
            >
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{ width: `${item.share}%` }"
                    data-test="detail-bar"
                />
            </div>
            <p v-if="item.detail" class="mt-1 text-xs text-muted-foreground">
                {{ item.detail }}
            </p>
            <div
                v-if="item.inside"
                class="mt-3 ml-2 border-l border-border pl-3"
                data-test="detail-inside"
            >
                <h4 class="text-xs font-medium text-muted-foreground">
                    {{ item.inside.title }}
                </h4>
                <DetailBarList class="mt-2" :items="item.inside.items" small />
            </div>
        </li>
    </ul>
</template>
