<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ShareLinkSection from '@/components/clients/ShareLinkSection.vue';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/machines/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { scannedAgo } from '@/lib/scannedAgo';
import { destroy, index, update } from '@/routes/clients';
import { show as showMachine } from '@/routes/machines';
import { update as assign } from '@/routes/machines/client';
import type { AssignableMachine, ClientDetails, ClientMachine } from '@/types';

const props = defineProps<{
    client: ClientDetails;
    machines: ClientMachine[];
    available_machines: AssignableMachine[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Clients', href: index() }],
    },
});

const form = useForm({ name: props.client.name });
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
            <h2 class="text-lg font-semibold">Name</h2>
            <form
                class="flex max-w-xl items-start gap-2"
                @submit.prevent="rename"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="name" class="sr-only">Name</Label>
                    <Input id="name" v-model="form.name" maxlength="255" />
                    <InputError :message="form.errors.name" />
                </div>
                <Button type="submit" size="sm" :disabled="form.processing">
                    Rename
                </Button>
            </form>
        </section>

        <ShareLinkSection
            :client-id="client.id"
            :share-url="client.share_url"
        />

        <section class="flex flex-col gap-3" data-test="client-machines">
            <h2 class="text-lg font-semibold">Machines</h2>

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
                    class="flex items-center justify-between gap-4 rounded-xl border border-border p-3"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <StatusBadge :status="machine.status" />
                        <Link
                            :href="showMachine(machine.id)"
                            class="truncate font-medium underline-offset-4 hover:underline"
                        >
                            {{ title(machine) }}
                        </Link>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        data-test="unassign"
                        @click="setClient(machine.id, null)"
                    >
                        Remove
                    </Button>
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
