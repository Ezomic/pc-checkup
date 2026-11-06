<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import AdviceList from '@/components/machines/AdviceList.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { useLocale } from '@/composables/useLocale';
import { show } from '@/routes/share';
import type { SharedMachine, SharedReport } from '@/types';

const props = defineProps<{
    token: string;
    client: { name: string };
    machine: SharedMachine;
    report: SharedReport | null;
}>();

const { t, ago, number, deviceType } = useLocale();

const title = computed(
    () =>
        props.machine.name ?? props.machine.model ?? t('share.unnamedMachine'),
);

const details = computed(() =>
    [
        props.machine.os,
        deviceType(props.machine.device_type),
        props.machine.model,
    ]
        .filter((part): part is string => part !== null)
        .join(' · '),
);

const verdict = computed(() => {
    if (props.report === null) {
        return t('share.notChecked');
    }

    return props.report.advice[0]?.headline ?? t('share.nothingFlagged');
});

const tiles = computed(() => {
    if (props.report === null) {
        return [];
    }

    const { free_disk, memory, cpu } = props.report.facts;

    return [
        { label: t('share.freeSpace'), value: free_disk },
        { label: t('share.memory'), value: memory },
        { label: t('share.processor'), value: cpu },
    ]
        .filter((tile) => tile.value !== null)
        .map((tile) => ({ ...tile, value: number(tile.value ?? '') }));
});
</script>

<template>
    <Head :title="`${title} - ${client.name}`" />

    <Link
        :href="show(token)"
        class="flex items-center gap-1 self-start text-sm text-muted-foreground underline-offset-4 hover:underline"
        data-test="back"
    >
        <ArrowLeft class="size-4" />
        {{ client.name }}
    </Link>

    <div class="flex flex-col gap-2">
        <StatusBadge
            v-if="machine.status"
            :status="machine.status"
            class="self-start"
        />
        <h1 class="text-2xl font-semibold break-words">{{ title }}</h1>
        <p class="text-sm text-muted-foreground">
            {{ details }}
            <template v-if="report && report.scanned_at">
                · {{ t('share.lastChecked', { when: ago(report.scanned_at) }) }}
            </template>
        </p>
    </div>

    <section class="flex flex-col gap-4">
        <p class="text-3xl font-semibold" data-test="verdict">
            {{ verdict }}
        </p>

        <p
            v-if="
                report && report.fixed_count !== null && report.fixed_count > 0
            "
            class="flex items-center gap-2 text-sm font-medium text-green-700 dark:text-green-400"
            data-test="fixed-note"
        >
            <CircleCheck class="size-4" aria-hidden="true" />
            {{
                t(
                    report.fixed_count === 1
                        ? 'share.fixedOne'
                        : 'share.fixedMany',
                    { n: report.fixed_count },
                )
            }}
        </p>

        <div
            v-if="tiles.length > 0"
            class="grid gap-3 sm:grid-cols-3"
            data-test="tiles"
        >
            <div
                v-for="tile in tiles"
                :key="tile.label"
                class="rounded-lg bg-muted p-4"
            >
                <p class="text-sm text-muted-foreground">{{ tile.label }}</p>
                <p class="text-2xl font-semibold break-words">
                    {{ tile.value }}
                </p>
            </div>
        </div>
    </section>

    <section
        v-if="report && report.advice.length > 0"
        class="flex flex-col gap-3"
    >
        <h2 class="text-lg font-semibold">{{ t('share.whatFirst') }}</h2>
        <AdviceList :advice="report.advice" />
    </section>
</template>
