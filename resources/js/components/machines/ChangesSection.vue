<script setup lang="ts">
import { ArrowRight, CircleCheck, CircleDot, CirclePlus } from '@lucide/vue';
import { computed } from 'vue';
import { newlyCheckedLine } from '@/lib/newlyChecked';
import type { ReportChanges } from '@/types';

const props = defineProps<{
    changes: ReportChanges;
}>();

const comparedWith = computed(() => {
    if (props.changes.previous_at === null) {
        return null;
    }

    const date = new Date(props.changes.previous_at);
    const sameYear = date.getUTCFullYear() === new Date().getUTCFullYear();

    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: sameYear ? undefined : 'numeric',
        timeZone: 'UTC',
    });
});

const unchanged = computed(
    () =>
        props.changes.new.length === 0 &&
        props.changes.fixed.length === 0 &&
        props.changes.disk === null &&
        props.changes.memory === null,
);

const numbers = computed(() =>
    [
        {
            label: 'Free space',
            change: props.changes.disk,
            suffix: ' free',
        },
        { label: 'Memory', change: props.changes.memory, suffix: '' },
    ].filter((row) => row.change !== null),
);

const newlyChecked = computed(() =>
    newlyCheckedLine(props.changes.newly_checked),
);
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border p-4"
        data-test="changes"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">Since the last check</h2>
            <p v-if="comparedWith" class="text-sm text-muted-foreground">
                Compared with the check on {{ comparedWith }}.
            </p>
        </div>

        <p v-if="unchanged" data-test="nothing-changed">
            Nothing changed since the last check.
        </p>

        <template v-else>
            <ul class="flex flex-wrap gap-x-6 gap-y-2" data-test="counts">
                <li class="flex items-center gap-2 font-medium">
                    <CirclePlus class="size-4" aria-hidden="true" />
                    {{ changes.new.length }} new
                </li>
                <li class="flex items-center gap-2 font-medium">
                    <CircleCheck class="size-4" aria-hidden="true" />
                    {{ changes.fixed.length }} fixed
                </li>
                <li class="flex items-center gap-2 font-medium">
                    <CircleDot class="size-4" aria-hidden="true" />
                    {{ changes.open }} still open
                </li>
            </ul>

            <div v-if="changes.new.length > 0" data-test="new-list">
                <h3 class="text-sm font-medium">New</h3>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="headline in changes.new"
                        :key="headline"
                        class="flex items-start gap-2"
                    >
                        <CirclePlus
                            class="mt-0.5 size-4 shrink-0 text-amber-800 dark:text-amber-300"
                            aria-hidden="true"
                        />
                        {{ headline }}
                    </li>
                </ul>
            </div>

            <div v-if="changes.fixed.length > 0" data-test="fixed-list">
                <h3 class="text-sm font-medium">Fixed</h3>
                <p
                    v-if="!changes.same_rights"
                    class="mt-1 text-sm text-muted-foreground"
                    data-test="rights-note"
                >
                    Scanned with different rights, fixed items may be unreliable
                </p>
                <ul class="mt-1 flex flex-col gap-1 text-sm">
                    <li
                        v-for="headline in changes.fixed"
                        :key="headline"
                        class="flex items-start gap-2"
                    >
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-green-700 dark:text-green-400"
                            aria-hidden="true"
                        />
                        {{ headline }}
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
