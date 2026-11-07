<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create as createClient, show as showClient } from '@/routes/clients';
import { index, show as showMachine } from '@/routes/machines';
import { approve, reject } from '@/routes/pairings';
import type { PairingClient, PairingDetails } from '@/types';

const props = defineProps<{
    pairing: PairingDetails;
    clients: PairingClient[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});

const NEW_MACHINE = 'new';

const clientId = ref('');
const machineId = ref(NEW_MACHINE);
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

const secondsLeft = computed(() =>
    Math.max(
        0,
        Math.ceil(
            (new Date(props.pairing.expiresAt).getTime() - now.value) / 1000,
        ),
    ),
);

const countdown = computed(
    () =>
        `${Math.floor(secondsLeft.value / 60)}:${String(secondsLeft.value % 60).padStart(2, '0')}`,
);

const status = computed(() =>
    props.pairing.status === 'pending' && secondsLeft.value === 0
        ? 'expired'
        : props.pairing.status,
);

const machines = computed(
    () =>
        props.clients.find((client) => String(client.id) === clientId.value)
            ?.machines ?? [],
);

const target = computed(() => {
    const client = props.pairing.client?.name ?? '';
    const machine = props.pairing.machine?.label ?? 'a new machine';

    return `${client} (${machine})`;
});

watch(clientId, () => {
    machineId.value = NEW_MACHINE;
});

function submit(): void {
    processing.value = true;
    router.post(
        approve(props.pairing.id).url,
        {
            client_id: Number(clientId.value),
            machine_id:
                machineId.value === NEW_MACHINE
                    ? null
                    : Number(machineId.value),
        },
        {
            preserveScroll: true,
            onError: (received) => {
                errors.value = received;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function decline(): void {
    router.post(reject(props.pairing.id).url, {}, { preserveScroll: true });
}

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Approve a scan" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Approve a scan</h1>

        <template v-if="status === 'pending'">
            <div
                class="flex max-w-md flex-col gap-2 rounded-lg border p-4"
                data-test="pairing-check"
            >
                <p class="text-sm text-muted-foreground">
                    Check that the computer shows this code before you approve.
                </p>
                <p
                    class="font-mono text-5xl font-semibold tracking-widest text-primary"
                    data-test="pairing-check-code"
                >
                    {{ pairing.checkCode }}
                </p>
                <p
                    class="text-sm text-muted-foreground"
                    data-test="pairing-countdown"
                >
                    Expires in {{ countdown }}.
                </p>
            </div>

            <p
                v-if="clients.length === 0"
                class="max-w-md text-sm text-muted-foreground"
            >
                You have no clients yet.
                <Link
                    :href="createClient()"
                    class="underline underline-offset-4"
                    data-test="pairing-add-client"
                >
                    Add a client
                </Link>
                first, then open this link again.
            </p>

            <form
                v-else
                class="flex max-w-md flex-col gap-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-2">
                    <Label for="pairing-client">Client</Label>
                    <select
                        id="pairing-client"
                        v-model="clientId"
                        class="h-11 min-w-0 rounded-md border border-input bg-transparent px-3 text-base shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:h-9 sm:text-sm"
                        data-test="pairing-client"
                    >
                        <option value="" disabled>Choose a client</option>
                        <option
                            v-for="client in clients"
                            :key="client.id"
                            :value="String(client.id)"
                        >
                            {{ client.name }}
                        </option>
                    </select>
                    <InputError :message="errors.client_id" />
                </div>

                <div v-if="clientId !== ''" class="grid gap-2">
                    <Label for="pairing-machine">Machine</Label>
                    <select
                        id="pairing-machine"
                        v-model="machineId"
                        class="h-11 min-w-0 rounded-md border border-input bg-transparent px-3 text-base shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:h-9 sm:text-sm"
                        data-test="pairing-machine"
                    >
                        <option :value="NEW_MACHINE">New machine</option>
                        <option
                            v-for="machine in machines"
                            :key="machine.id"
                            :value="String(machine.id)"
                        >
                            {{ machine.label }}
                        </option>
                    </select>
                    <InputError :message="errors.machine_id" />
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <Button
                        type="submit"
                        class="h-11 sm:h-9"
                        :disabled="clientId === '' || processing"
                        data-test="pairing-approve"
                    >
                        <Spinner v-if="processing" />
                        Approve upload
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        class="h-11 sm:h-9"
                        :disabled="processing"
                        data-test="pairing-reject"
                        @click="decline"
                    >
                        Reject
                    </Button>
                </div>
            </form>
        </template>

        <div
            v-else
            class="flex max-w-md flex-col gap-2 rounded-lg border p-4 text-sm"
            data-test="pairing-outcome"
        >
            <template v-if="status === 'approved'">
                <p class="font-medium">Approved.</p>
                <p>
                    The report goes to {{ target }} within a few seconds and
                    then shows up under
                    <Link
                        v-if="pairing.client"
                        :href="showClient(pairing.client.id)"
                        class="underline underline-offset-4"
                    >
                        {{ pairing.client.name }} </Link
                    >.
                </p>
            </template>
            <template v-else-if="status === 'used'">
                <p class="font-medium">Uploaded.</p>
                <p>
                    The report is in
                    <Link
                        v-if="pairing.machine"
                        :href="showMachine(pairing.machine.id)"
                        class="underline underline-offset-4"
                    >
                        {{ pairing.machine.label }}
                    </Link>
                    <template v-if="pairing.client">
                        for {{ pairing.client.name }}</template
                    >.
                </p>
            </template>
            <template v-else-if="status === 'rejected'">
                <p class="font-medium">Rejected.</p>
                <p class="text-muted-foreground">
                    Nothing was uploaded. The report stays on the computer.
                </p>
            </template>
            <template v-else>
                <p class="font-medium">This request has expired.</p>
                <p class="text-muted-foreground">
                    Nothing was uploaded. Run the script on the computer again
                    for a new QR code.
                </p>
            </template>
        </div>
    </div>
</template>
