<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { AfterFixReport, FixStatus } from '@/types';

defineProps<{
    afterFix: AfterFixReport;
}>();

const statusClasses: Record<FixStatus, string> = {
    applied:
        'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400',
    skipped: 'text-muted-foreground',
    failed: 'border-red-600/40 bg-red-600/15 text-red-700 dark:text-red-400',
};
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">After fixes</h2>
        <p v-if="afterFix.before" class="text-sm text-muted-foreground">
            After-fix report from {{ afterFix.created_at.slice(0, 10) }},
            compared with the audit from
            {{ afterFix.before.created_at.slice(0, 10) }}.
        </p>
        <p v-else class="text-sm text-muted-foreground">
            After-fix report from {{ afterFix.created_at.slice(0, 10) }}. The
            audit it follows is not on file, so there is nothing to compare it
            with.
        </p>

        <div v-if="afterFix.before" class="grid gap-3 sm:grid-cols-3">
            <div
                class="rounded-xl border border-green-600/40 bg-green-600/5 p-3 text-sm"
            >
                <h3 class="font-medium">
                    Resolved ({{ afterFix.resolved.length }})
                </h3>
                <ul class="mt-1 flex flex-col gap-1">
                    <li v-for="flag in afterFix.resolved" :key="flag">
                        {{ flag }}
                    </li>
                    <li
                        v-if="afterFix.resolved.length === 0"
                        class="text-muted-foreground"
                    >
                        Nothing resolved.
                    </li>
                </ul>
            </div>
            <div
                class="rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm"
            >
                <h3 class="font-medium">
                    Still open ({{ afterFix.open.length }})
                </h3>
                <ul class="mt-1 flex flex-col gap-1">
                    <li v-for="flag in afterFix.open" :key="flag">
                        {{ flag }}
                    </li>
                    <li
                        v-if="afterFix.open.length === 0"
                        class="text-muted-foreground"
                    >
                        Nothing left open.
                    </li>
                </ul>
            </div>
            <div
                v-if="afterFix.introduced.length > 0"
                class="rounded-xl border border-red-600/40 bg-red-600/5 p-3 text-sm"
            >
                <h3 class="font-medium">
                    New since the audit ({{ afterFix.introduced.length }})
                </h3>
                <ul class="mt-1 flex flex-col gap-1">
                    <li v-for="flag in afterFix.introduced" :key="flag">
                        {{ flag }}
                    </li>
                </ul>
            </div>
        </div>

        <h3 class="text-base font-semibold">Fix log</h3>
        <p
            v-if="afterFix.fixes.length === 0"
            class="text-sm text-muted-foreground"
        >
            No fixes were logged.
        </p>
        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="fix in afterFix.fixes"
                :key="fix.id"
                class="flex flex-col gap-1 rounded-xl border border-sidebar-border/70 p-3 text-sm"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ fix.id }}</span>
                    <Badge variant="outline" :class="statusClasses[fix.status]">
                        {{ fix.status }}
                    </Badge>
                </div>
                <code class="text-xs break-all">{{ fix.command }}</code>
                <p class="text-xs text-muted-foreground">
                    <template v-if="fix.undo">Undo: {{ fix.undo }}</template>
                    <template v-else>No undo available.</template>
                </p>
            </li>
        </ul>
    </section>
</template>
