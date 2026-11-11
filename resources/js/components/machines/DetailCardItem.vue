<script setup lang="ts">
import { ChevronDown, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import DetailBarList from '@/components/machines/DetailBarList.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { splitBars, startsOpen, summaryLine } from '@/lib/detailCards';
import type { DetailCard, DetailRow, DetailStatus, DetailTone } from '@/types';

const props = defineProps<{
    card: DetailCard;
}>();

const open = ref(startsOpen(props.card));
const showMore = ref(false);
const showNotes = ref(false);

const line = computed(() => summaryLine(props.card));
const bars = computed(() => splitBars(props.card.bars ?? []));
const notes = computed(() => props.card.notes ?? []);

const dots: Record<DetailStatus, string> = {
    attention: 'bg-red-600 dark:bg-red-500',
    fine: 'bg-green-600 dark:bg-green-500',
    neutral: 'bg-muted-foreground/40',
};

const statusLabels: Record<DetailStatus, string> = {
    attention: ', needs attention.',
    fine: ', fine.',
    neutral: ', for information.',
};

const tones: Record<DetailTone, string> = {
    good: 'text-green-700 dark:text-green-400',
    bad: 'text-red-700 dark:text-red-400',
    neutral: '',
};

const LONG_ROW = 60;

const moreId = computed(() => `detail-${props.card.id}-more`);
const notesId = computed(() => `detail-${props.card.id}-notes`);

function isLong(row: DetailRow): boolean {
    return row.label.length + row.value.length > LONG_ROW;
}

function lists(count: number): string {
    return count === 1 ? '1 more list' : `${count} more lists`;
}
</script>

<template>
    <Collapsible
        v-model:open="open"
        class="rounded-xl border border-border"
        :data-test="`detail-card-${card.id}`"
        :data-status="card.status"
    >
        <h3>
            <CollapsibleTrigger
                class="flex w-full items-start gap-3 rounded-xl p-4 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="detail-toggle"
            >
                <span
                    class="mt-1.5 size-2.5 shrink-0 rounded-full"
                    :class="dots[card.status]"
                    aria-hidden="true"
                    data-test="detail-dot"
                />
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="font-semibold">{{ card.title }}</span>
                    <span class="sr-only">{{ statusLabels[card.status] }}</span>
                    <span
                        v-if="!open && line"
                        class="text-sm font-normal break-words text-muted-foreground print:hidden"
                        data-test="detail-line"
                        >{{ line }}</span
                    >
                </span>
                <ChevronDown
                    class="mt-1 size-4 shrink-0 text-muted-foreground transition-transform print:hidden"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </CollapsibleTrigger>
        </h3>

        <CollapsibleContent
            force-mount
            class="flex flex-col gap-3 px-4 pb-4 data-[state=closed]:hidden print:data-[state=closed]:flex"
            data-test="detail-body"
        >
            <p
                v-if="card.summary"
                class="text-sm font-medium break-words"
                data-test="detail-summary"
            >
                {{ card.summary }}
            </p>

            <dl
                v-if="card.rows.length > 0"
                class="grid grid-cols-[minmax(0,1fr)_minmax(40%,auto)] gap-x-4 gap-y-2 text-sm"
            >
                <template v-for="row in card.rows" :key="row.label">
                    <template v-if="isLong(row)">
                        <dt
                            class="col-span-2 font-medium break-words"
                            :class="tones[row.tone]"
                            :data-tone="row.tone"
                        >
                            {{ row.label }}
                        </dt>
                        <dd
                            class="col-span-2 -mt-1 break-words text-muted-foreground"
                        >
                            {{ row.value }}
                        </dd>
                    </template>
                    <template v-else>
                        <dt class="break-words text-muted-foreground">
                            {{ row.label }}
                        </dt>
                        <dd
                            class="text-right font-medium break-words"
                            :class="tones[row.tone]"
                            :data-tone="row.tone"
                        >
                            {{ row.value }}
                        </dd>
                    </template>
                </template>
            </dl>

            <div
                v-for="group in bars.shown"
                :key="group.title"
                data-test="detail-bars"
            >
                <h4 class="text-sm font-medium">{{ group.title }}</h4>
                <DetailBarList class="mt-2" :items="group.items" />
            </div>

            <template v-if="bars.more.length > 0">
                <div
                    :id="moreId"
                    class="flex-col gap-3"
                    :class="showMore ? 'flex' : 'hidden print:flex'"
                    data-test="detail-more"
                >
                    <div
                        v-for="group in bars.more"
                        :key="group.title"
                        data-test="detail-bars"
                    >
                        <h4 class="text-sm font-medium">{{ group.title }}</h4>
                        <DetailBarList class="mt-2" :items="group.items" />
                    </div>
                </div>
                <button
                    type="button"
                    class="self-start text-sm font-medium text-primary underline-offset-4 hover:underline print:hidden"
                    :aria-expanded="showMore"
                    :aria-controls="moreId"
                    data-test="detail-show-more"
                    @click="showMore = !showMore"
                >
                    {{
                        showMore
                            ? 'Show less'
                            : `Show more (${lists(bars.more.length)})`
                    }}
                </button>
            </template>

            <div v-if="card.list" class="print:hidden" data-test="detail-list">
                <h4 class="text-sm font-medium">{{ card.list.title }}</h4>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="item in card.list.items"
                        :key="item.label"
                        class="flex flex-col"
                    >
                        <span class="min-w-0 font-medium break-words">{{
                            item.label
                        }}</span>
                        <span
                            class="min-w-0 text-xs break-words text-muted-foreground"
                            >{{ item.value }}</span
                        >
                    </li>
                </ul>
            </div>

            <div v-if="notes.length > 0" class="flex flex-col gap-1">
                <button
                    type="button"
                    class="flex items-center gap-1 self-start text-xs text-muted-foreground hover:text-foreground print:hidden"
                    :aria-expanded="showNotes"
                    :aria-controls="notesId"
                    data-test="detail-about"
                    @click="showNotes = !showNotes"
                >
                    <Info class="size-3.5" aria-hidden="true" />
                    {{ showNotes ? 'Hide notes' : 'About this card' }}
                </button>
                <div
                    :id="notesId"
                    class="flex-col gap-1"
                    :class="showNotes ? 'flex' : 'hidden print:flex'"
                    data-test="detail-notes"
                >
                    <p
                        v-for="note in notes"
                        :key="note"
                        class="text-xs text-muted-foreground"
                        data-test="detail-note"
                    >
                        {{ note }}
                    </p>
                </div>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
