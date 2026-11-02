<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { create, show } from '@/routes/clients';
import { update } from '@/routes/machines/client';
import type { ClientOption } from '@/types';

const props = defineProps<{
    machineId: number;
    client: ClientOption | null;
    clients: ClientOption[];
}>();

const selected = ref<string>(
    props.client === null ? '' : String(props.client.id),
);

function save(): void {
    router.put(
        update(props.machineId).url,
        { client_id: selected.value === '' ? null : Number(selected.value) },
        { preserveScroll: true },
    );
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="client-section">
        <h2 class="text-lg font-semibold">Client</h2>

        <p v-if="client" class="text-sm">
            With
            <Link
                :href="show(client.id)"
                class="font-medium underline underline-offset-4"
            >
                {{ client.name }}
            </Link>
        </p>
        <p v-else class="text-sm text-muted-foreground">
            Not assigned to a client.
        </p>

        <p v-if="clients.length === 0" class="text-sm text-muted-foreground">
            <Link :href="create()" class="underline underline-offset-4">
                Add a client
            </Link>
            to assign this machine.
        </p>

        <form
            v-else
            class="flex max-w-md items-center gap-2"
            @submit.prevent="save"
        >
            <label for="machine-client" class="sr-only">Client</label>
            <select
                id="machine-client"
                v-model="selected"
                class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <option value="">No client</option>
                <option
                    v-for="option in clients"
                    :key="option.id"
                    :value="String(option.id)"
                >
                    {{ option.name }}
                </option>
            </select>
            <Button type="submit" size="sm" data-test="save-client">
                Save
            </Button>
        </form>
    </section>
</template>
