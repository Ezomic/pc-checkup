<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { useBenchmark } from '@/composables/useBenchmark';
import type { MachineBenchmark } from '@/types';

defineProps<{
    benchmark: MachineBenchmark;
}>();

const {
    formatValue,
    formatDelta,
    deltaClass,
    rangeHint,
    ratingClass,
    ratingLabel,
} = useBenchmark();
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Benchmark</h2>
        <p class="text-sm text-muted-foreground">
            Latest run from {{ benchmark.latest.created_at.slice(0, 10) }} ({{
                benchmark.latest.kind
            }}).
        </p>

        <ul class="grid gap-2 sm:grid-cols-2">
            <li
                v-for="metric in benchmark.latest.metrics"
                :key="metric.key"
                class="flex flex-col gap-1 rounded-xl border border-sidebar-border/70 p-3 text-sm"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ metric.label }}</span>
                    <Badge
                        variant="outline"
                        :class="ratingClass(metric.rating)"
                    >
                        {{ ratingLabel(metric.rating) }}
                    </Badge>
                </div>
                <span class="text-xl font-semibold">
                    {{ formatValue(metric.value, metric.unit) }}
                </span>
                <span class="text-xs text-muted-foreground">
                    {{
                        rangeHint(
                            metric.healthy,
                            metric.poor,
                            metric.higher_is_better,
                            metric.unit,
                        )
                    }}
                </span>
            </li>
        </ul>

        <template v-if="benchmark.comparison">
            <h3 class="text-base font-semibold">Before and after fixes</h3>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="row in benchmark.comparison"
                    :key="row.key"
                    class="flex flex-wrap items-baseline justify-between gap-x-3 rounded-xl border border-sidebar-border/70 p-3 text-sm"
                >
                    <span class="font-medium">{{ row.label }}</span>
                    <span>
                        {{ formatValue(row.before, row.unit) }}
                        to
                        {{ formatValue(row.after, row.unit) }}
                        <span :class="deltaClass(row.improved)">
                            ({{ formatDelta(row.delta, row.unit) }})
                        </span>
                    </span>
                </li>
            </ul>
        </template>

        <h3 class="text-base font-semibold">Earlier runs</h3>
        <p
            v-if="benchmark.history.length === 1"
            class="text-sm text-muted-foreground"
        >
            This is the only benchmark run so far.
        </p>
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="run in benchmark.history"
                :key="run.id"
                class="rounded-xl border border-sidebar-border/70 p-3 text-sm"
            >
                <p class="font-medium">
                    {{ run.created_at.slice(0, 10) }}
                    <span class="font-normal text-muted-foreground">
                        ({{ run.kind }})
                    </span>
                </p>
                <dl class="mt-1 grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5">
                    <template v-for="value in run.values" :key="value.key">
                        <dt class="text-muted-foreground">{{ value.label }}</dt>
                        <dd class="text-right">
                            {{ formatValue(value.value, value.unit) }}
                            <span :class="deltaClass(value.improved)">
                                ({{ formatDelta(value.delta, value.unit) }})
                            </span>
                        </dd>
                    </template>
                </dl>
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">
            Cross-machine comparison is not shown: the chip is not stored for a
            machine, so two machines cannot be matched reliably.
        </p>
    </section>
</template>
