<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ScanLine } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import CheckupDownloads from '@/components/CheckupDownloads.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { show as showMachine, index } from '@/routes/machines';
import { show, status as statusRoute, store } from '@/routes/scans';

type ScanStatus = 'none' | 'waiting' | 'received' | 'expired';

const props = defineProps<{
    code: string | null;
    expiresAt: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Machines', href: index() },
            { title: 'Start a scan', href: show() },
        ],
    },
});

const POLL_MS = 2000;

const status = ref<ScanStatus>(props.code === null ? 'none' : 'waiting');
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

const secondsLeft = computed(() =>
    props.expiresAt === null
        ? 0
        : Math.max(
              0,
              Math.ceil(
                  (new Date(props.expiresAt).getTime() - now.value) / 1000,
              ),
          ),
);

const countdown = computed(() => {
    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = String(secondsLeft.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

const waiting = computed(
    () => status.value === 'waiting' && secondsLeft.value > 0,
);

const spaced = computed(() =>
    props.code === null
        ? ''
        : `${props.code.slice(0, 3)} ${props.code.slice(3)}`,
);

async function poll(): Promise<void> {
    now.value = Date.now();

    try {
        const response = await fetch(statusRoute().url, {
            headers: { Accept: 'application/json' },
        });
        const data = (await response.json()) as {
            status: ScanStatus;
            machine_id: number | null;
        };

        if (data.status === 'received' && data.machine_id !== null) {
            status.value = 'received';
            stop();
            router.visit(showMachine(data.machine_id));
        } else if (data.status === 'expired') {
            status.value = 'expired';
            stop();
        }
    } catch {
        // A dropped request just means the next tick asks again.
    }
}

function stop(): void {
    clearInterval(timer);
    timer = undefined;
}

function start(): void {
    router.post(store().url);
}

onMounted(() => {
    if (props.code !== null) {
        timer = setInterval(() => void poll(), POLL_MS);
    }
});

onBeforeUnmount(stop);
</script>

<template>
    <Head title="Start a scan" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Start a scan</h1>

        <div
            v-if="code !== null && (waiting || status === 'received')"
            class="flex max-w-xl flex-col gap-4 rounded-lg border p-6"
            data-test="scan-waiting"
        >
            <p class="text-sm text-muted-foreground">
                On the PC you want to check, run the checkup script and type
                this code when it asks. No login or token needed.
            </p>
            <p
                class="font-mono text-5xl font-semibold tracking-widest text-primary"
                data-test="scan-code"
            >
                {{ spaced }}
            </p>
            <p
                class="flex items-center gap-2 text-sm text-muted-foreground"
                data-test="scan-status"
            >
                <Spinner />
                <span v-if="status === 'received'">
                    Report received, opening the machine...
                </span>
                <span v-else>
                    Waiting for the report. The code works once and expires in
                    {{ countdown }}.
                </span>
            </p>
        </div>

        <div
            v-else
            class="flex max-w-xl flex-col gap-2 rounded-lg border border-dashed p-6 text-sm"
            data-test="scan-idle"
        >
            <p v-if="status === 'expired' || (code !== null && !waiting)">
                That code has expired. Start a new scan to get a fresh one.
            </p>
            <p v-else class="text-muted-foreground">
                No code yet. Start a scan to get a 6 digit code that lets a PC
                send its report without logging in.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <Button @click="start">
                <ScanLine class="size-4" />
                {{ code === null ? 'Start a scan' : 'New code' }}
            </Button>
        </div>

        <div class="flex max-w-xl flex-col gap-2 rounded-lg border p-4">
            <p class="text-sm font-medium">Need the script?</p>
            <CheckupDownloads />
        </div>
    </div>
</template>
