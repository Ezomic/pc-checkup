<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { computed, reactive } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { update } from '@/routes/machines/checklist';
import type { ChecklistItem, ChecklistOutcome } from '@/types';

const props = defineProps<{
    machineId: number;
    items: ChecklistItem[];
}>();

const groupTitles: Record<string, string> = {
    common: 'General',
    laptop: 'Laptop',
    desktop: 'Desktop',
    macos: 'macOS',
    windows: 'Windows',
};

const outcomes: { value: ChecklistOutcome; label: string }[] = [
    { value: 'pass', label: 'Pass' },
    { value: 'fail', label: 'Fail' },
    { value: 'na', label: 'N/A' },
];

const notes = reactive<Record<string, string>>(
    Object.fromEntries(props.items.map((item) => [item.key, item.note ?? ''])),
);

const groups = computed(() => {
    const byGroup = new Map<string, ChecklistItem[]>();

    for (const item of props.items) {
        byGroup.set(item.group, [...(byGroup.get(item.group) ?? []), item]);
    }

    return [...byGroup.entries()];
});

const checked = computed(
    () => props.items.filter((item) => item.outcome !== null).length,
);

let queue: Promise<void> = Promise.resolve();

function save(item: ChecklistItem, outcome: ChecklistOutcome | null): void {
    const note = outcome === null ? null : notes[item.key];

    queue = queue.then(
        () =>
            new Promise<void>((resolve) => {
                router.put(
                    update({ machine: props.machineId, item: item.key }).url,
                    { outcome, note },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        onFinish: () => resolve(),
                    },
                );
            }),
    );
}

function choose(item: ChecklistItem, outcome: ChecklistOutcome): void {
    if (item.outcome === outcome) {
        notes[item.key] = '';
        save(item, null);

        return;
    }

    save(item, outcome);
}

function saveNote(item: ChecklistItem): void {
    if ((notes[item.key] || null) !== item.note) {
        save(item, item.outcome);
    }
}
</script>

<template>
    <Collapsible as="section" class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">
            <CollapsibleTrigger
                class="group flex w-full items-center justify-between gap-2 rounded-md text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span class="flex items-center gap-2">
                    <ChevronRight
                        class="size-4 transition-transform group-data-[state=open]:rotate-90"
                        aria-hidden="true"
                    />
                    Manual checklist
                </span>
                <span class="text-sm font-normal text-muted-foreground">
                    {{ checked }} of {{ items.length }} done
                </span>
            </CollapsibleTrigger>
        </h2>

        <CollapsibleContent class="flex flex-col gap-4">
            <div v-for="[group, groupItems] in groups" :key="group">
                <h3 class="mb-2 text-sm font-medium text-muted-foreground">
                    {{ groupTitles[group] ?? group }}
                </h3>
                <ul class="flex flex-col gap-2">
                    <li
                        v-for="item in groupItems"
                        :key="item.key"
                        class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <p class="text-sm">{{ item.label }}</p>
                            <div class="flex gap-1">
                                <Button
                                    v-for="option in outcomes"
                                    :key="option.value"
                                    type="button"
                                    size="sm"
                                    :variant="
                                        item.outcome === option.value
                                            ? option.value === 'fail'
                                                ? 'destructive'
                                                : 'default'
                                            : 'outline'
                                    "
                                    :aria-pressed="
                                        item.outcome === option.value
                                    "
                                    @click="choose(item, option.value)"
                                >
                                    {{ option.label }}
                                </Button>
                            </div>
                        </div>
                        <input
                            v-if="item.outcome !== null"
                            v-model="notes[item.key]"
                            type="text"
                            maxlength="1000"
                            placeholder="Note (optional)"
                            :aria-label="`Note for ${item.label}`"
                            class="rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @blur="saveNote(item)"
                        />
                    </li>
                </ul>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
