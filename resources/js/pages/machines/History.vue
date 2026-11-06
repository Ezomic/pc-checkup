<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ScanComparison from '@/components/machines/ScanComparison.vue';
import Paginator from '@/components/Paginator.vue';
import { Button } from '@/components/ui/button';
import { scanMoment } from '@/lib/scanDate';
import { history, index, show } from '@/routes/machines';
import type {
    HistoryMachine,
    HistoryScan,
    Paginated,
    ScanComparisonResult,
} from '@/types';

const props = defineProps<{
    machine: HistoryMachine;
    scans: Paginated<HistoryScan>;
    comparison: ScanComparisonResult | null;
    comparison_error: string | null;
    selected: number[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});

const MAX_SELECTED = 2;

const chosen = ref<number[]>([...props.selected]);

const title = computed(
    () => props.machine.name ?? props.machine.model ?? 'Unnamed machine',
);

const verdictColours = {
    Keep: 'text-green-700 dark:text-green-400',
    Repair: 'text-amber-800 dark:text-amber-300',
    Replace: 'text-red-700 dark:text-red-400',
};

const offPage = computed(
    () =>
        chosen.value.filter(
            (id) => !props.scans.data.some((scan) => scan.id === id),
        ).length,
);

function toggle(id: number): void {
    chosen.value = chosen.value.includes(id)
        ? chosen.value.filter((picked) => picked !== id)
        : [...chosen.value, id];
}

function locked(id: number): boolean {
    return chosen.value.length >= MAX_SELECTED && !chosen.value.includes(id);
}

function compare(): void {
    const [from, to] = chosen.value;

    router.get(
        history(props.machine.id, { query: { from, to } }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function clear(): void {
    chosen.value = [];
    router.get(history(props.machine.id), {}, { preserveScroll: true });
}

function rights(scan: HistoryScan): string | null {
    if (scan.ran_as_admin === null) {
        return null;
    }

    return scan.ran_as_admin ? 'Ran as administrator' : 'Not as administrator';
}
</script>

<template>
    <Head :title="`Scan history of ${title}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 pt-8">
        <div class="flex flex-col gap-1">
            <Link
                :href="show(machine.id)"
                class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                data-test="back-to-machine"
            >
                Back to {{ title }}
            </Link>
            <h1 class="text-2xl font-semibold break-words">Scan history</h1>
            <p class="text-sm text-muted-foreground">
                {{ scans.total }}
                {{ scans.total === 1 ? 'scan' : 'scans' }}, newest first. Pick
                two to see what changed.
            </p>
        </div>

        <p
            v-if="comparison_error"
            class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            role="alert"
            data-test="comparison-error"
        >
            {{ comparison_error }}
        </p>

        <ScanComparison v-if="comparison" :comparison="comparison" />

        <p
            v-if="scans.data.length === 0"
            class="text-sm text-muted-foreground"
            data-test="no-scans"
        >
            No scans yet. Run the check on this machine to start a history.
        </p>

        <template v-else>
            <div
                class="flex flex-wrap items-center gap-2"
                data-test="compare-bar"
            >
                <Button
                    size="sm"
                    :disabled="chosen.length !== MAX_SELECTED"
                    data-test="compare"
                    @click="compare"
                >
                    Compare selected
                </Button>
                <Button
                    v-if="chosen.length > 0 || comparison"
                    size="sm"
                    variant="ghost"
                    data-test="clear"
                    @click="clear"
                >
                    Clear
                </Button>
                <span class="text-sm text-muted-foreground">
                    {{ chosen.length }} of {{ MAX_SELECTED }} selected
                    <template v-if="offPage > 0">
                        ({{ offPage }} on another page)
                    </template>
                </span>
            </div>

            <ul class="flex flex-col gap-2" data-test="scan-list">
                <li
                    v-for="scan in scans.data"
                    :key="scan.id"
                    class="flex items-start gap-3 rounded-xl border border-border p-3"
                    data-test="scan-row"
                >
                    <input
                        type="checkbox"
                        class="mt-1 size-4 shrink-0 accent-primary"
                        :checked="chosen.includes(scan.id)"
                        :disabled="locked(scan.id)"
                        :aria-label="`Select the scan of ${scanMoment(scan.created_at)}`"
                        data-test="scan-pick"
                        @change="toggle(scan.id)"
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <span class="font-medium">
                                {{ scanMoment(scan.created_at) }}
                            </span>
                            <span
                                v-if="scan.kind === 'after-fix'"
                                class="text-xs text-muted-foreground"
                            >
                                After fixes
                            </span>
                            <span
                                v-if="scan.script_version"
                                class="text-xs text-muted-foreground"
                                data-test="script-version"
                            >
                                Script version {{ scan.script_version }}
                            </span>
                        </div>
                        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                            <div v-if="scan.free_disk" class="flex gap-1">
                                <dt class="text-muted-foreground">Free disk</dt>
                                <dd data-test="free-disk">
                                    {{ scan.free_disk }}
                                </dd>
                            </div>
                            <div class="flex gap-1">
                                <dt class="text-muted-foreground">Flags</dt>
                                <dd data-test="flags-count">
                                    {{ scan.flags_count }}
                                </dd>
                            </div>
                            <div v-if="scan.verdict" class="flex gap-1">
                                <dt class="text-muted-foreground">Verdict</dt>
                                <dd
                                    class="font-medium"
                                    :class="verdictColours[scan.verdict]"
                                    data-test="verdict"
                                >
                                    {{ scan.verdict }}
                                </dd>
                            </div>
                            <div v-if="rights(scan)" class="flex gap-1">
                                <dt class="sr-only">Rights</dt>
                                <dd
                                    class="text-muted-foreground"
                                    data-test="rights"
                                >
                                    {{ rights(scan) }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </li>
            </ul>

            <Paginator :meta="scans" />
        </template>
    </div>
</template>
