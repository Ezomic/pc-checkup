<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, ScanLine, Upload } from '@lucide/vue';
import StaleNote from '@/components/machines/StaleNote.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import Paginator from '@/components/Paginator.vue';
import { Button } from '@/components/ui/button';
import { scannedAgo } from '@/lib/scannedAgo';
import { create } from '@/routes/imports';
import { create as createMachine, index, show } from '@/routes/machines';
import { store as startScan } from '@/routes/scans';
import type { MachineCard, Paginated } from '@/types';

defineProps<{
    machines: Paginated<MachineCard>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});

function title(machine: MachineCard): string {
    return machine.name ?? machine.model ?? 'Unnamed machine';
}

function kind(machine: MachineCard): string {
    return [machine.os, machine.device_type]
        .filter((part): part is string => part !== null)
        .join(' ');
}

function headline(machine: MachineCard): string {
    if (machine.last_report_at === null) {
        return 'No scan yet. Start a scan or import a report.';
    }

    return machine.top_issue?.headline ?? 'Nothing flagged in the latest scan.';
}
</script>

<template>
    <Head title="Machines" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 pt-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Your machines</h1>
                <p class="text-sm text-muted-foreground">
                    The latest scan of each one and its biggest problem.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    size="sm"
                    data-test="start-scan"
                    @click="router.post(startScan().url)"
                >
                    <ScanLine class="size-4" />
                    Start a scan
                </Button>
                <Button as-child size="sm" variant="outline">
                    <Link :href="createMachine()">
                        <Plus class="size-4" />
                        Add machine
                    </Link>
                </Button>
                <Button as-child size="sm" variant="outline">
                    <Link :href="create()">
                        <Upload class="size-4" />
                        Import report
                    </Link>
                </Button>
            </div>
        </div>

        <p
            v-if="machines.data.length === 0"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            No machines yet. Start a scan, import a checkup report or add a
            machine by hand.
        </p>

        <ul
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
            data-test="machine-cards"
        >
            <li v-for="machine in machines.data" :key="machine.id">
                <Link
                    :href="show(machine.id)"
                    class="flex h-full flex-col gap-3 rounded-xl border border-border bg-card p-5 transition-colors hover:border-primary/50"
                >
                    <StatusBadge :status="machine.status" class="self-start" />
                    <div>
                        <p class="text-lg font-medium">{{ title(machine) }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ kind(machine) }}
                        </p>
                    </div>
                    <p class="text-sm" data-test="headline">
                        {{ headline(machine) }}
                    </p>
                    <p
                        v-if="machine.issue_count > 1"
                        class="text-sm text-muted-foreground"
                    >
                        +{{ machine.issue_count - 1 }} more to look at
                    </p>
                    <p
                        v-if="machine.last_report_at"
                        class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 pt-2 text-xs text-muted-foreground"
                    >
                        <span>
                            Scanned {{ scannedAgo(machine.last_report_at) }}
                        </span>
                        <StaleNote v-if="machine.stale" />
                    </p>
                </Link>
            </li>
        </ul>

        <Paginator :meta="machines" />
    </div>
</template>
