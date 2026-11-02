<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { scannedAgo } from '@/lib/scannedAgo';
import { machine as showMachine } from '@/routes/share';
import type { SharedMachineCard } from '@/types';

defineProps<{
    token: string;
    client: { name: string };
    machines: SharedMachineCard[];
}>();

function title(machine: SharedMachineCard): string {
    return machine.name ?? machine.model ?? 'Unnamed machine';
}

function kind(machine: SharedMachineCard): string {
    return [machine.os, machine.device_type]
        .filter((part): part is string => part !== null)
        .join(' ');
}

function headline(machine: SharedMachineCard): string {
    if (machine.scanned_at === null) {
        return 'Not checked yet.';
    }

    return (
        machine.top_issue?.headline ?? 'Nothing flagged in the latest check.'
    );
}
</script>

<template>
    <Head :title="client.name" />

    <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold break-words" data-test="client-name">
            {{ client.name }}
        </h1>
        <p class="text-sm text-muted-foreground">
            The latest check of each computer and its biggest problem.
        </p>
    </div>

    <p
        v-if="machines.length === 0"
        class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
    >
        No computers to show yet.
    </p>

    <ul v-else class="grid gap-4 sm:grid-cols-2" data-test="machine-cards">
        <li v-for="machine in machines" :key="machine.id">
            <Link
                :href="showMachine({ token, machine: machine.id })"
                class="flex h-full flex-col gap-3 rounded-xl border border-border bg-card p-5 transition-colors hover:border-primary/50"
            >
                <StatusBadge
                    v-if="machine.status"
                    :status="machine.status"
                    class="self-start"
                />
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
                    v-if="machine.scanned_at"
                    class="mt-auto pt-2 text-xs text-muted-foreground"
                >
                    Last checked {{ scannedAgo(machine.scanned_at) }}
                </p>
            </Link>
        </li>
    </ul>
</template>
