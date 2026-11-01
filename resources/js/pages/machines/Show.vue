<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed } from 'vue';
import ChecklistSection from '@/components/machines/ChecklistSection.vue';
import NotesSection from '@/components/machines/NotesSection.vue';
import PhotosSection from '@/components/machines/PhotosSection.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import StatusSection from '@/components/machines/StatusSection.vue';
import { Button } from '@/components/ui/button';
import { index, summary } from '@/routes/machines';
import type {
    ChecklistItem,
    MachineDetails,
    MachineNote,
    MachinePhoto,
    ReportSummary,
} from '@/types';

const props = defineProps<{
    machine: MachineDetails;
    report: ReportSummary | null;
    checklist: ChecklistItem[];
    notes: MachineNote[];
    photos: MachinePhoto[];
}>();

const title = computed(
    () => props.machine.name ?? props.machine.model ?? 'Unnamed machine',
);

const details = computed(() =>
    [props.machine.os, props.machine.device_type, props.machine.serial]
        .filter((part): part is string => part !== null)
        .join(' · '),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-semibold">{{ title }}</h1>
                    <StatusBadge :status="machine.status" />
                </div>
                <p class="text-sm text-muted-foreground">{{ details }}</p>
                <p v-if="machine.model" class="text-sm text-muted-foreground">
                    {{ machine.model }}
                </p>
            </div>
            <Button as-child size="sm" variant="outline">
                <Link :href="summary(machine.id)">
                    <FileText class="size-4" />
                    Printable summary
                </Link>
            </Button>
        </div>

        <StatusSection :machine-id="machine.id" :status="machine.status" />

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Latest report</h2>

            <p v-if="report === null" class="text-sm text-muted-foreground">
                No report imported for this machine.
            </p>

            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Imported {{ report.created_at.slice(0, 10) }}
                </p>
                <ul
                    v-if="report.flags.length > 0"
                    class="flex flex-col gap-1 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
                >
                    <li v-for="flag in report.flags" :key="flag">
                        {{ flag }}
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Nothing was flagged automatically.
                </p>
                <details class="rounded-xl border border-sidebar-border/70 p-3">
                    <summary class="cursor-pointer text-sm">
                        Full report
                    </summary>
                    <pre
                        class="mt-3 max-h-96 overflow-auto text-xs whitespace-pre-wrap"
                        >{{ report.markdown }}</pre>
                </details>
            </template>
        </section>

        <ChecklistSection :machine-id="machine.id" :items="checklist" />
        <NotesSection :machine-id="machine.id" :notes="notes" />
        <PhotosSection :machine-id="machine.id" :photos="photos" />
    </div>
</template>
