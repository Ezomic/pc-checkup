<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import { index, show } from '@/routes/machines';
import type { AttentionLevel, NeedsAttentionOverview } from '@/types';

const props = defineProps<{
    overview: NeedsAttentionOverview;
}>();

const filters = computed(() =>
    props.overview.filters.filter(
        (filter) => filter.count > 0 || filter.key === props.overview.filter,
    ),
);

const chip =
    'rounded-full border px-3 py-1 text-sm font-medium transition-colors';
const chipActive = 'border-primary bg-primary text-primary-foreground';
const chipIdle = 'border-border hover:bg-muted';

const labels: Record<AttentionLevel, string> = {
    urgent: 'Urgent',
    soon: 'Soon',
};

const colours: Record<AttentionLevel, string> = {
    urgent: 'border-red-600/40 bg-red-600/15 text-red-700 dark:text-red-400',
    soon: 'border-amber-500/40 bg-amber-500/15 text-amber-800 dark:text-amber-300',
};
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 sm:p-5"
        data-test="needs-attention"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
            <h2 class="text-lg font-semibold">Needs attention</h2>
            <p
                v-if="overview.total > 0"
                class="text-sm text-muted-foreground"
                data-test="attention-summary"
            >
                {{ overview.urgent }} urgent, {{ overview.total }} in total
            </p>
        </div>

        <nav
            v-if="filters.length > 0"
            aria-label="Filter by reason"
            class="flex flex-wrap gap-2"
            data-test="attention-filters"
        >
            <Link
                :href="index()"
                :class="[
                    chip,
                    overview.filter === null ? chipActive : chipIdle,
                ]"
                :aria-current="overview.filter === null ? 'true' : undefined"
                data-test="attention-filter"
                preserve-scroll
            >
                All
            </Link>
            <Link
                v-for="filter in filters"
                :key="filter.key"
                :href="index({ query: { reason: filter.key } })"
                :class="[
                    chip,
                    overview.filter === filter.key ? chipActive : chipIdle,
                ]"
                :aria-current="
                    overview.filter === filter.key ? 'true' : undefined
                "
                data-test="attention-filter"
                preserve-scroll
            >
                {{ filter.label }} ({{ filter.count }})
            </Link>
        </nav>

        <p
            v-if="overview.total === 0 && overview.filter !== null"
            class="text-sm text-muted-foreground"
            data-test="attention-no-match"
        >
            No machine has this reason right now.
        </p>
        <p
            v-else-if="overview.total === 0"
            class="flex items-center gap-2 text-sm text-muted-foreground"
            data-test="attention-empty"
        >
            <CircleCheck
                class="size-4 shrink-0 text-green-600 dark:text-green-400"
                aria-hidden="true"
            />
            Everything looks fine. No machine needs attention right now.
        </p>

        <ul v-else class="flex flex-col divide-y divide-border">
            <li
                v-for="row in overview.rows"
                :key="row.id"
                data-test="attention-row"
            >
                <Link
                    :href="show(row.id)"
                    class="flex flex-col gap-2 py-3 transition-colors first:pt-0 last:pb-0 hover:text-primary sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                >
                    <div class="min-w-0">
                        <p class="font-medium break-words">{{ row.name }}</p>
                        <p
                            v-if="row.client"
                            class="text-sm break-words text-muted-foreground"
                            data-test="attention-client"
                        >
                            {{ row.client.name }}
                        </p>
                    </div>
                    <ul class="flex min-w-0 flex-col gap-1.5 sm:max-w-[60%]">
                        <li
                            v-for="reason in row.reasons"
                            :key="reason.text"
                            class="flex items-start gap-2 text-sm"
                            data-test="attention-reason"
                        >
                            <span
                                class="mt-0.5 w-16 shrink-0 rounded-full border px-2 py-0.5 text-center text-xs font-medium"
                                :class="colours[reason.level]"
                            >
                                {{ labels[reason.level] }}
                            </span>
                            <span class="break-words">{{ reason.text }}</span>
                        </li>
                        <li
                            v-if="row.reason_count > row.reasons.length"
                            class="text-xs text-muted-foreground"
                            data-test="attention-more"
                        >
                            +{{ row.reason_count - row.reasons.length }} more
                        </li>
                    </ul>
                </Link>
            </li>
        </ul>

        <Link
            v-if="
                !overview.showing_all && overview.total > overview.rows.length
            "
            :href="
                index({ query: { attention: 'all', reason: overview.filter } })
            "
            class="self-start text-sm font-medium text-primary underline-offset-4 hover:underline"
            data-test="attention-show-all"
            preserve-scroll
        >
            Show all {{ overview.total }}
        </Link>
        <Link
            v-else-if="overview.showing_all"
            :href="index({ query: { reason: overview.filter } })"
            class="self-start text-sm font-medium text-primary underline-offset-4 hover:underline"
            data-test="attention-show-fewer"
            preserve-scroll
        >
            Show fewer
        </Link>
    </section>
</template>
