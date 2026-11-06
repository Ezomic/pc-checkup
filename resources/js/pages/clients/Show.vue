<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ShareLinkSection from '@/components/clients/ShareLinkSection.vue';
import InputError from '@/components/InputError.vue';
import AttentionBadge from '@/components/machines/AttentionBadge.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { summaryLine } from '@/lib/clientSummary';
import { scannedAgo } from '@/lib/scannedAgo';
import { destroy, index, update } from '@/routes/clients';
import { history, show as showMachine } from '@/routes/machines';
import { update as assign } from '@/routes/machines/client';
import type {
    AssignableMachine,
    ClientDetails,
    ClientMachine,
    ClientMachineSummary,
} from '@/types';

const props = defineProps<{
    client: ClientDetails;
    summary: ClientMachineSummary;
    machines: ClientMachine[];
    available_machines: AssignableMachine[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Clients', href: index() }],
    },
});

const form = useForm({
    name: props.client.name,
    locale: props.client.locale,
});
const chosen = ref<string>('');

function rename(): void {
    form.put(update(props.client.id).url, { preserveScroll: true });
}

function remove(): void {
    if (
        window.confirm(
            `Delete ${props.client.name}? Its machines stay, but are no longer assigned to anyone, and the share link stops working.`,
        )
    ) {
        router.delete(destroy(props.client.id).url);
    }
}

function setClient(machineId: number, clientId: number | null): void {
    router.put(
        assign(machineId).url,
        { client_id: clientId },
        { preserveScroll: true },
    );
}

function add(): void {
    if (chosen.value === '') {
        return;
    }

    setClient(Number(chosen.value), props.client.id);
    chosen.value = '';
}

function title(machine: { name: string | null; model: string | null }): string {
    return machine.name ?? machine.model ?? 'Unnamed machine';
}

function optionLabel(machine: AssignableMachine): string {
    return machine.client_name === null
        ? title(machine)
        : `${title(machine)} (with ${machine.client_name})`;
}
</script>

<template>
    <Head :title="client.name" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4 pt-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <h1 class="text-2xl font-semibold break-words">
                    {{ client.name }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        client.last_viewed_at
                            ? `Link last viewed ${scannedAgo(client.last_viewed_at)}`
                            : 'Link never viewed'
                    }}
                </p>
            </div>
            <Button
                variant="outline"
                size="sm"
                data-test="delete-client"
                @click="remove"
            >
                <Trash2 class="size-4" />
                Delete client
            </Button>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Name and share page language</h2>
            <form
                class="flex max-w-xl items-start gap-2"
                @submit.prevent="rename"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="name" class="sr-only">Name</Label>
                    <Input id="name" v-model="form.name" maxlength="255" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="locale" class="sr-only">
                        Language of the share page
                    </Label>
                    <select
                        id="locale"
                        v-model="form.locale"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-test="client-locale"
                    >
                        <option value="nl">Share page in Dutch</option>
                        <option value="en">Share page in English</option>
                    </select>
                    <InputError :message="form.errors.locale" />
                </div>
                <Button type="submit" size="sm" :disabled="form.processing">
                    Save
                </Button>
            </form>
        </section>

        <ShareLinkSection
            :client-id="client.id"
            :share-url="client.share_url"
        />

        <section class="flex flex-col gap-3" data-test="client-machines">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                <h2 class="text-lg font-semibold">Machines</h2>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="machines-summary"
                >
                    {{ summaryLine(summary) }}
                </p>
            </div>

            <p
                v-if="machines.length === 0"
                class="text-sm text-muted-foreground"
            >
                No machines assigned yet.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="machine in machines"
                    :key="machine.id"
                    class="flex flex-col gap-3 rounded-xl border border-border p-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                    data-test="client-machine"
                >
                    <div class="flex min-w-0 flex-col gap-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <AttentionBadge :level="machine.level" />
                            <Link
                                :href="showMachine(machine.id)"
                                class="font-medium break-words underline-offset-4 hover:underline"
                            >
                                {{ title(machine) }}
                            </Link>
                            <StatusBadge :status="machine.status" />
                        </div>
                        <p
                            class="text-sm text-muted-foreground"
                            data-test="machine-meta"
                        >
                            <span v-if="machine.device_type" class="capitalize">
                                {{ machine.device_type }} ·
                            </span>
                            <span v-if="machine.last_scan_at">
                                Scanned {{ scannedAgo(machine.last_scan_at) }}
                            </span>
                            <span v-else>Not scanned yet</span>
                            <span v-if="machine.verdict">
                                · Worth keeping? {{ machine.verdict }}
                            </span>
                        </p>
                        <ul
                            v-if="machine.reasons.length > 0"
                            class="flex flex-col gap-0.5 text-sm"
                        >
                            <li
                                v-for="reason in machine.reasons"
                                :key="reason.text"
                                class="break-words"
                                data-test="machine-reason"
                            >
                                {{ reason.text }}
                            </li>
                            <li
                                v-if="
                                    machine.reason_count >
                                    machine.reasons.length
                                "
                                class="text-xs text-muted-foreground"
                            >
                                +{{
                                    machine.reason_count -
                                    machine.reasons.length
                                }}
                                more
                            </li>
                        </ul>
                        <p
                            v-else
                            class="text-sm text-muted-foreground"
                            data-test="machine-nothing"
                        >
                            Nothing to do right now.
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <Button
                            as-child
                            variant="outline"
                            size="sm"
                            data-test="client-machine-history"
                        >
                            <Link :href="history(machine.id)">History</Link>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            data-test="unassign"
                            @click="setClient(machine.id, null)"
                        >
                            Remove
                        </Button>
                    </div>
                </li>
            </ul>

            <form
                v-if="available_machines.length > 0"
                class="flex max-w-xl items-center gap-2"
                @submit.prevent="add"
            >
                <label for="add-machine" class="sr-only">Add a machine</label>
                <select
                    id="add-machine"
                    v-model="chosen"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <option value="">Add a machine...</option>
                    <option
                        v-for="machine in available_machines"
                        :key="machine.id"
                        :value="String(machine.id)"
                    >
                        {{ optionLabel(machine) }}
                    </option>
                </select>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="chosen === ''"
                    data-test="assign"
                >
                    Add
                </Button>
            </form>
        </section>
    </div>
</template>
