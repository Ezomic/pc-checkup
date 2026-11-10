<script setup lang="ts">
import { ArrowRight, CircleCheck, CircleDot, CirclePlus } from '@lucide/vue';
import { computed } from 'vue';
import { newlyCheckedLine } from '@/lib/newlyChecked';
import { scanMoment } from '@/lib/scanDate';
import type { ScanComparisonResult } from '@/types';

const props = defineProps<{
    comparison: ScanComparisonResult;
}>();

const numbers = computed(() =>
    [
        {
            label: 'Free space',
            change: props.comparison.disk,
            suffix: ' free',
        },
        { label: 'Memory', change: props.comparison.memory, suffix: '' },
        { label: 'Verdict', change: props.comparison.verdict, suffix: '' },
    ].filter((row) => row.change !== null),
);

const unchanged = computed(
    () =>
        props.comparison.new.length === 0 &&
        props.comparison.fixed.length === 0 &&
        numbers.value.length === 0,
);

const newlyChecked = computed(() =>
    newlyCheckedLine(props.comparison.newly_checked),
);
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:p-5"
        data-test="comparison"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">What changed</h2>
            <p class="text-sm break-words text-muted-foreground">
                {{ scanMoment(comparison.before.created_at) }}
                to
                {{ scanMoment(comparison.after.created_at) }}
            </p>
        </div>

        <p v-if="unchanged" data-test="comparison-unchanged">
            Nothing changed between these two scans.
        </p>

        <template v-else>
            <ul class="flex flex-wrap gap-x-6 gap-y-2" data-test="counts">
                <li class="flex items-center gap-2 font-medium">
                    <CirclePlus class="size-4" aria-hidden="true" />
                    {{ comparison.new.length }} new
                </li>
                <li class="flex items-center gap-2 font-medium">
                    <CircleCheck class="size-4" aria-hidden="true" />
                    {{ comparison.fixed.length }} fixed
                </li>
                <li class="flex items-center gap-2 font-medium">
                    <CircleDot class="size-4" aria-hidden="true" />
                    {{ comparison.open }} still open
                </li>
            </ul>

            <div v-if="comparison.new.length > 0" data-test="new-list">
                <h3 class="text-sm font-medium">New</h3>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="headline in comparison.new"
                        :key="headline"
                        class="flex items-start gap-2"
                    >
                        <CirclePlus
                            class="mt-0.5 size-4 shrink-0 text-amber-800 dark:text-amber-300"
                            aria-hidden="true"
                        />
                        <span class="break-words">{{ headline }}</span>
                    </li>
                </ul>
            </div>

            <div v-if="comparison.fixed.length > 0" data-test="fixed-list">
                <h3 class="text-sm font-medium">Fixed</h3>
                <p
                    v-if="!comparison.same_rights"
                    class="mt-1 text-sm text-muted-foreground"
                    data-test="rights-note"
                >
                    Scanned with different rights, fixed items may be unreliable
                </p>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="headline in comparison.fixed"
                        :key="headline"
                        class="flex items-start gap-2"
                    >
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-green-700 dark:text-green-400"
                            aria-hidden="true"
                        />
                        <span class="break-words">{{ headline }}</span>
                    </li>
                </ul>
            </div>

            <ul
                v-if="numbers.length > 0"
                class="flex flex-col gap-1 text-sm"
                data-test="numbers"
            >
                <li
                    v-for="row in numbers"
                    :key="row.label"
                    class="flex flex-wrap items-center gap-x-2"
                >
                    <span class="text-muted-foreground">{{ row.label }}:</span>
                    <span>{{ row.change?.from }}{{ row.suffix }}</span>
                    <ArrowRight class="size-4" aria-label="changed to" />
                    <span class="font-medium"
                        >{{ row.change?.to }}{{ row.suffix }}</span
                    >
                </li>
            </ul>
        </template>

        <p
            v-if="newlyChecked"
            class="text-sm break-words text-muted-foreground"
            data-test="newly-checked"
        >
            {{ newlyChecked }}
        </p>
    </section>
</template>
