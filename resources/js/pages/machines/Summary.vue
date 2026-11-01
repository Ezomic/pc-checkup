<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/machines';
import type {
    MachineDetails,
    MachineNote,
    SummaryChecklist,
    SummaryReport,
} from '@/types';

const props = defineProps<{
    machine: MachineDetails;
    report: SummaryReport | null;
    checklist: SummaryChecklist;
    notes: MachineNote[];
}>();

const title = computed(
    () => props.machine.name ?? props.machine.model ?? 'Unnamed machine',
);

const details = computed(() => [
    { label: 'Model', value: props.machine.model },
    { label: 'OS', value: props.machine.os },
    { label: 'Device type', value: props.machine.device_type },
    { label: 'Serial', value: props.machine.serial },
    { label: 'Hardware UUID', value: props.machine.hardware_uuid },
]);

const sections = computed(
    () =>
        [
            { key: 'fail', title: 'Failed', items: props.checklist.fail },
            { key: 'pass', title: 'Passed', items: props.checklist.pass },
            { key: 'na', title: 'Not applicable', items: props.checklist.na },
            {
                key: 'unchecked',
                title: 'Not checked',
                items: props.checklist.unchecked,
            },
        ] as const,
);

function print(): void {
    window.print();
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Summary: ${title}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex flex-col gap-2">
                <h1 class="text-xl font-semibold">{{ title }}</h1>
                <StatusBadge :status="machine.status" />
            </div>
            <Button type="button" size="sm" class="print:hidden" @click="print">
                <Printer class="size-4" />
                Print
            </Button>
        </div>

        <dl class="grid grid-cols-[max-content_1fr] gap-x-6 gap-y-1 text-sm">
            <template v-for="detail in details" :key="detail.label">
                <dt class="text-muted-foreground">{{ detail.label }}</dt>
                <dd>{{ detail.value ?? 'Not known' }}</dd>
            </template>
        </dl>

        <section class="flex flex-col gap-2">
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
                    class="list-disc pl-5 text-sm"
                >
                    <li v-for="flag in report.flags" :key="flag">
                        {{ flag }}
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    Nothing was flagged automatically.
                </p>
            </template>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Checklist</h2>
            <template v-for="section in sections" :key="section.key">
                <div v-if="section.items.length > 0">
                    <h3 class="mb-2 text-sm font-medium">
                        {{ section.title }} ({{ section.items.length }})
                    </h3>
                    <ul class="flex flex-col gap-1 text-sm">
                        <li
                            v-for="item in section.items"
                            :key="item.key"
                            class="break-inside-avoid"
                        >
                            {{ item.label
                            }}<span
                                v-if="item.note"
                                class="text-muted-foreground"
                                >: {{ item.note }}</span
                            >
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-lg font-semibold">Notes</h2>
            <p v-if="notes.length === 0" class="text-sm text-muted-foreground">
                No notes.
            </p>
            <ul v-else class="flex flex-col gap-2 text-sm">
                <li
                    v-for="note in notes"
                    :key="note.id"
                    class="break-inside-avoid whitespace-pre-wrap"
                >
                    {{ note.body }}
                </li>
            </ul>
        </section>
    </div>
</template>
