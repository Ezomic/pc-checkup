<script setup lang="ts">
import { ArrowRight, CircleCheck, CircleDot, CirclePlus } from '@lucide/vue';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { scanMoment } from '@/lib/scannedAgo';
import type { BeforeAfter, BeforeAfterNumber, SharedMachine } from '@/types';

type ConditionKey =
    'normal' | 'service_recommended' | 'replace_soon' | 'replace_now' | 'poor';

const props = defineProps<{
    machine: SharedMachine;
    clientName: string | null;
    comparison: BeforeAfter;
}>();

const { locale, t, number, deviceType } = useLocale();

const title = computed(
    () =>
        props.machine.name ?? props.machine.model ?? t('share.unnamedMachine'),
);

const details = computed(() =>
    [
        props.clientName,
        props.machine.os,
        deviceType(props.machine.device_type),
        props.machine.model,
    ]
        .filter((part): part is string => part !== null)
        .join(' · '),
);

const scans = computed(() =>
    [
        { label: t('ba.before'), at: props.comparison.before },
        { label: t('ba.after'), at: props.comparison.after },
    ].filter((scan): scan is { label: string; at: string } => scan.at !== null),
);

const summary = computed(() => {
    const { fixed, open, new: added, same_rights } = props.comparison;
    const todo = open.length > 0 ? [t('ba.todo', { n: open.length })] : [];

    if (!same_rights) {
        return [t('ba.rightsSummary'), ...todo].join(' ');
    }

    const parts = [
        ...(fixed.length > 0
            ? [
                  t(fixed.length === 1 ? 'ba.fixedOne' : 'ba.fixedMany', {
                      n: fixed.length,
                  }),
              ]
            : []),
        ...todo,
        ...(added.length > 0 ? [t('ba.newCount', { n: added.length })] : []),
    ];

    return parts.length > 0 ? parts.join(', ') : t('ba.unchanged');
});

const allClear = computed(
    () =>
        props.comparison.same_rights &&
        props.comparison.open.length + props.comparison.new.length === 0 &&
        props.comparison.fixed.length > 0,
);

function value(row: BeforeAfterNumber, side: string): string {
    return row.key === 'battery_condition'
        ? t(`ba.condition.${side as ConditionKey}` as const)
        : number(side);
}
</script>

<template>
    <article
        class="flex flex-col gap-6 print:gap-4"
        data-test="before-after"
        :lang="locale"
    >
        <header class="flex flex-col gap-2">
            <p class="text-sm text-muted-foreground">
                {{ t('ba.title') }}
            </p>
            <h1 class="text-2xl font-semibold break-words" data-test="title">
                {{ title }}
            </h1>
            <p v-if="details" class="text-sm text-muted-foreground">
                {{ details }}
            </p>
            <dl
                class="flex flex-wrap gap-x-8 gap-y-1 text-sm"
                data-test="dates"
            >
                <div v-for="scan in scans" :key="scan.label" class="flex gap-2">
                    <dt class="text-muted-foreground">{{ scan.label }}:</dt>
                    <dd class="font-medium">
                        {{ scanMoment(scan.at, locale) }}
                    </dd>
                </div>
            </dl>
        </header>

        <p class="text-2xl font-semibold" data-test="summary">
            {{ summary }}
        </p>

        <p
            v-if="!comparison.same_rights"
            class="rounded-lg bg-muted p-3 text-sm"
            data-test="rights-note"
        >
            {{ t('ba.rightsNote') }}
        </p>

        <section
            v-if="comparison.fixed.length > 0"
            class="flex flex-col gap-2 print:break-inside-avoid"
            data-test="fixed"
        >
            <h2 class="text-lg font-semibold">
                {{
                    comparison.same_rights
                        ? t('ba.fixed')
                        : t('ba.noLongerReported')
                }}
            </h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="headline in comparison.fixed"
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
        </section>

        <section
            v-if="comparison.open.length > 0"
            class="flex flex-col gap-2 print:break-inside-avoid"
            data-test="open"
        >
            <h2 class="text-lg font-semibold">{{ t('ba.open') }}</h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="headline in comparison.open"
                    :key="headline"
                    class="flex items-start gap-2"
                >
                    <CircleDot
                        class="mt-0.5 size-4 shrink-0 text-amber-800 dark:text-amber-300"
                        aria-hidden="true"
                    />
                    {{ headline }}
                </li>
            </ul>
        </section>

        <section
            v-if="comparison.new.length > 0"
            class="flex flex-col gap-2 print:break-inside-avoid"
            data-test="new"
        >
            <h2 class="text-lg font-semibold">{{ t('ba.new') }}</h2>
            <ul class="flex flex-col gap-1">
                <li
                    v-for="headline in comparison.new"
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
        </section>

        <p v-if="allClear" class="font-medium" data-test="all-clear">
            {{ t('ba.allClear') }}
        </p>

        <section
            v-if="comparison.numbers.length > 0"
            class="flex flex-col gap-2 print:break-inside-avoid"
            data-test="numbers"
        >
            <h2 class="text-lg font-semibold">{{ t('ba.numbers') }}</h2>
            <ul class="flex flex-col gap-2 text-sm">
                <li
                    v-for="row in comparison.numbers"
                    :key="row.key"
                    class="flex flex-wrap items-center gap-x-2"
                    :data-test="`number-${row.key}`"
                >
                    <span class="text-muted-foreground"
                        >{{ t(`ba.number.${row.key}` as const) }}:</span
                    >
                    <span>{{ value(row, row.from) }}</span>
                    <ArrowRight class="size-4" aria-hidden="true" />
                    <span class="font-medium">{{ value(row, row.to) }}</span>
                    <span
                        v-if="row.improved !== null"
                        class="text-xs"
                        :class="
                            row.improved
                                ? 'text-green-700 dark:text-green-400'
                                : 'text-amber-800 dark:text-amber-300'
                        "
                        >({{
                            row.improved ? t('ba.improved') : t('ba.worse')
                        }})</span
                    >
                </li>
            </ul>
        </section>
    </article>
</template>
