<script setup lang="ts">
import { computed } from 'vue';
import { checkedOn, shortDate } from '@/lib/scannedAgo';
import { sparkline } from '@/lib/sparkline';
import type { Trend } from '@/types';

const props = defineProps<{
    trends: Trend[];
}>();

const WIDTH = 200;
const HEIGHT = 48;

const rows = computed(() =>
    props.trends.map((trend) => {
        const first = trend.points[0] ?? null;
        const last = trend.points[trend.points.length - 1] ?? null;
        const firstDate = first === null ? null : shortDate(first.at);
        const lastDate = last === null ? null : shortDate(last.at);
        const range =
            first === null || last === null
                ? ''
                : ` ${first.label} on ${firstDate} to ${last.label} on ${lastDate},`;

        return {
            trend,
            firstDate,
            lastDate,
            changeText:
                firstDate === null
                    ? trend.change
                    : `${trend.change} since ${firstDate}`,
            drawing: sparkline(trend.points, WIDTH, HEIGHT),
            description: `${trend.title}:${range} ${trend.change}.`,
        };
    }),
);
</script>

<template>
    <section
        v-if="trends.length > 0"
        class="flex flex-col gap-4 rounded-xl border border-border p-4 print:break-inside-avoid"
        data-test="trends"
    >
        <h2 class="text-lg font-semibold">Trends</h2>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="row in rows"
                :key="row.trend.id"
                class="flex min-w-0 flex-col gap-2"
                :data-test="`trend-${row.trend.id}`"
            >
                <h3 class="text-sm font-medium text-muted-foreground">
                    {{ row.trend.title }}
                </h3>
                <p class="text-2xl font-semibold">{{ row.trend.latest }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ row.changeText }}
                </p>

                <template v-if="row.drawing.last">
                    <svg
                        :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
                        preserveAspectRatio="none"
                        role="img"
                        :aria-label="row.description"
                        class="h-12 w-full fill-none stroke-current"
                        :class="
                            row.trend.tone === 'bad'
                                ? 'text-red-600'
                                : 'text-neutral-500'
                        "
                    >
                        <path
                            :d="row.drawing.path"
                            stroke-width="2"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            vector-effect="non-scaling-stroke"
                        />
                        <path
                            :d="`M${row.drawing.last.x} ${row.drawing.last.y}h0`"
                            stroke-width="7"
                            stroke-linecap="round"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>
                    <div
                        class="flex justify-between text-xs text-muted-foreground"
                    >
                        <span data-test="trend-first">{{ row.firstDate }}</span>
                        <span data-test="trend-last">{{ row.lastDate }}</span>
                    </div>
                </template>

                <p
                    v-if="row.trend.sentence"
                    class="text-sm"
                    :class="row.trend.tone === 'bad' ? 'text-red-600' : ''"
                    data-test="trend-sentence"
                >
                    {{ row.trend.sentence }}
                </p>

                <table class="sr-only">
                    <caption>
                        {{
                            row.trend.title
                        }}
                        at each scan
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="point in row.trend.points" :key="point.at">
                            <td>{{ checkedOn(point.at) }}</td>
                            <td>{{ point.label }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</template>
