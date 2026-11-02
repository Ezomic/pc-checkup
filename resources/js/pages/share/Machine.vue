<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import AdviceList from '@/components/machines/AdviceList.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { scannedAgo } from '@/lib/scannedAgo';
import { show } from '@/routes/share';
import type { SharedMachine, SharedReport } from '@/types';

const props = defineProps<{
    token: string;
    client: { name: string };
    machine: SharedMachine;
    report: SharedReport | null;
}>();

const title = computed(
    () => props.machine.name ?? props.machine.model ?? 'Unnamed machine',
);

const details = computed(() =>
    [props.machine.os, props.machine.device_type, props.machine.model]
        .filter((part): part is string => part !== null)
        .join(' · '),
);

const verdict = computed(() => {
    if (props.report === null) {
        return 'Not checked yet';
    }

    return (
        props.report.advice[0]?.headline ??
        'Nothing flagged in the latest check'
    );
});

const tiles = computed(() => {
    if (props.report === null) {
        return [];
    }

    const { free_disk, memory, cpu } = props.report.facts;

    return [
        { label: 'Free space', value: free_disk },
        { label: 'Memory', value: memory },
        { label: 'Processor', value: cpu },
    ].filter((tile) => tile.value !== null);
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
                · Checked {{ scannedAgo(report.scanned_at) }}
            </template>
        </p>
    </div>

    <section class="flex flex-col gap-4">
        <p class="text-3xl font-semibold" data-test="verdict">
            {{ verdict }}
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
        <h2 class="text-lg font-semibold">What to do first</h2>
        <AdviceList :advice="report.advice" />
    </section>
</template>
