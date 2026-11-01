<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Upload } from '@lucide/vue';
import Paginator from '@/components/Paginator.vue';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/imports';
import { index, show } from '@/routes/machines';
import type { Machine, Paginated } from '@/types';

defineProps<{
    machines: Paginated<Machine>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});

function title(machine: Machine): string {
    return machine.name ?? machine.model ?? 'Unnamed machine';
}

function details(machine: Machine): string {
    return [machine.os, machine.device_type, machine.serial ?? 'no serial']
        .filter((part): part is string => part !== null)
        .join(' · ');
}
</script>

<template>
    <Head title="Machines" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Machines</h1>
            <Button as-child size="sm">
                <Link :href="create()">
                    <Upload class="size-4" />
                    Import report
                </Link>
            </Button>
        </div>

        <p
            v-if="machines.data.length === 0"
            class="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground"
        >
            No machines yet. Import a checkup report to add the first one.
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="machine in machines.data"
                :key="machine.id"
                class="flex items-start justify-between gap-4 rounded-xl border border-sidebar-border/70 p-4"
            >
                <div class="min-w-0">
                    <Link
                        :href="show(machine.id)"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ title(machine) }}
                    </Link>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ details(machine) }}
                    </p>
                    <p
                        v-if="machine.model && machine.name"
                        class="truncate text-sm text-muted-foreground"
                    >
                        {{ machine.model }}
                    </p>
                </div>
                <div class="shrink-0 text-right text-sm text-muted-foreground">
                    <p>
                        {{ machine.reports_count }}
                        {{ machine.reports_count === 1 ? 'report' : 'reports' }}
                    </p>
                    <p v-if="machine.last_report_at">
                        Last {{ machine.last_report_at.slice(0, 10) }}
                    </p>
                </div>
            </li>
        </ul>

        <Paginator :meta="machines" />
    </div>
</template>
