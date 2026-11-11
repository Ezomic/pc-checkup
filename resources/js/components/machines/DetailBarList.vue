<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { reactive, useId } from 'vue';
import type { DetailBar } from '@/types';

defineProps<{
    items: DetailBar[];
    small?: boolean;
}>();

const listId = useId();
const open = reactive(new Set<number>());

function toggle(index: number): void {
    if (open.has(index)) {
        open.delete(index);
    } else {
        open.add(index);
    }
}
</script>

<template>
    <ul
        class="flex flex-col text-sm"
        :class="small ? 'gap-2 text-xs' : 'gap-3'"
    >
        <li v-for="(item, index) in items" :key="`${index}-${item.label}`">
            <div class="flex justify-between gap-4">
                <button
                    v-if="item.children?.length"
                    type="button"
                    class="flex min-w-0 items-start gap-1 rounded-sm text-left break-words hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="open.has(index)"
                    :aria-controls="`${listId}-${index}`"
                    data-test="detail-toggle"
                    @click="toggle(index)"
                >
                    <ChevronRight
                        class="mt-0.5 shrink-0 transition-transform"
                        :class="[
                            small ? 'size-3' : 'size-4',
                            { 'rotate-90': open.has(index) },
                        ]"
                        aria-hidden="true"
                    />
                    <span class="min-w-0">{{ item.label }}</span>
                </button>
                <span v-else class="min-w-0 break-words">{{ item.label }}</span>
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
                v-if="item.children?.length"
                v-show="open.has(index)"
                :id="`${listId}-${index}`"
                class="mt-3 ml-2 border-l border-border pl-3"
                data-test="detail-children"
            >
                <DetailBarList :items="item.children" small />
            </div>
            <div
                v-if="item.inside"
                class="mt-3 ml-2 border-l border-border pl-3"
                data-test="detail-inside"
            >
                <h5 class="text-xs font-medium text-muted-foreground">
                    {{ item.inside.title }}
                </h5>
                <DetailBarList class="mt-2" :items="item.inside.items" small />
            </div>
        </li>
    </ul>
</template>
