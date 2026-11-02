<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed } from 'vue';
import AdviceList from '@/components/machines/AdviceList.vue';
import AfterFixSection from '@/components/machines/AfterFixSection.vue';
import BenchmarkSection from '@/components/machines/BenchmarkSection.vue';
import ChangesSection from '@/components/machines/ChangesSection.vue';
import ChecklistSection from '@/components/machines/ChecklistSection.vue';
import ClientSection from '@/components/machines/ClientSection.vue';
import NotesSection from '@/components/machines/NotesSection.vue';
import PhotosSection from '@/components/machines/PhotosSection.vue';
import RemoteSupportSection from '@/components/machines/RemoteSupportSection.vue';
import StaleNote from '@/components/machines/StaleNote.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import StatusSection from '@/components/machines/StatusSection.vue';
import { Button } from '@/components/ui/button';
import { scannedAgo } from '@/lib/scannedAgo';
import { index, summary } from '@/routes/machines';
import type {
    AfterFixReport,
    ChecklistItem,
    ClientOption,
    LatestReport,
    MachineBenchmark,
    MachineNote,
    MachinePhoto,
    OwnerMachineDetails,
    ReportChanges,
} from '@/types';

const props = defineProps<{
    machine: OwnerMachineDetails;
    client: ClientOption | null;
    clients: ClientOption[];
    report: LatestReport | null;
    changes: ReportChanges | null;
    afterFix: AfterFixReport | null;
    benchmark: MachineBenchmark | null;
    checklist: ChecklistItem[];
    notes: MachineNote[];
    photos: MachinePhoto[];
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
        return 'No scan yet';
    }

    return (
        props.report.advice[0]?.headline ?? 'Nothing flagged in the latest scan'
    );
});

const tiles = computed(() => {
    if (props.report === null) {
        return [];
    }

    const { free_disk, memory } = props.report.facts;

    return [
        { label: 'Free space', value: free_disk },
        { label: 'Memory', value: memory },
        { label: 'To look at', value: String(props.report.advice.length) },
    ].filter((tile) => tile.value !== null);
});

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4 pt-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <StatusBadge :status="machine.status" class="self-start" />
                <h1 class="text-2xl font-semibold">{{ title }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ details }}
                    <template v-if="report">
                        · Scanned {{ scannedAgo(report.created_at) }}
                    </template>
                </p>
                <template v-if="report?.stale">
                    <StaleNote class="text-sm" />
                    <p
                        class="text-sm text-muted-foreground"
                        data-test="stale-hint"
                    >
                        Run the check again to refresh this report.
                    </p>
                </template>
            </div>
            <Button as-child size="sm" variant="outline">
                <Link :href="summary(machine.id)">
                    <FileText class="size-4" />
                    Printable summary
                </Link>
            </Button>
        </div>

        <section class="flex flex-col gap-4">
            <p class="text-3xl font-semibold" data-test="verdict">
                {{ verdict }}
            </p>
            <p v-if="report === null" class="text-sm text-muted-foreground">
                Start a scan or import a report to see how this machine is
                doing.
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
                    <p class="text-sm text-muted-foreground">
                        {{ tile.label }}
                    </p>
                    <p class="text-2xl font-semibold">{{ tile.value }}</p>
                </div>
            </div>
        </section>

        <ChangesSection v-if="changes" :changes="changes" />

        <section
            v-if="report && report.advice.length > 0"
            class="flex flex-col gap-3"
        >
            <h2 class="text-lg font-semibold">What to do first</h2>
            <AdviceList :advice="report.advice" />
        </section>

        <StatusSection :machine-id="machine.id" :status="machine.status" />
        <RemoteSupportSection
            :machine-id="machine.id"
            :rustdesk-id="machine.rustdesk_id"
        />
        <ClientSection
            :machine-id="machine.id"
            :client="client"
            :clients="clients"
        />

        <details v-if="report" class="rounded-xl border border-border p-3">
            <summary class="cursor-pointer text-sm">Full report</summary>
            <pre
                class="mt-3 max-h-96 overflow-auto text-xs whitespace-pre-wrap"
                >{{ report.markdown }}</pre>
        </details>

        <AfterFixSection v-if="afterFix" :after-fix="afterFix" />
        <BenchmarkSection v-if="benchmark" :benchmark="benchmark" />

        <ChecklistSection :machine-id="machine.id" :items="checklist" />
        <NotesSection :machine-id="machine.id" :notes="notes" />
        <PhotosSection :machine-id="machine.id" :photos="photos" />
    </div>
</template>
