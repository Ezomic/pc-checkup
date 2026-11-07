<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

defineProps<{ problems: string[] }>();
</script>

<template>
    <Alert
        class="border-amber-500/40 bg-amber-500/10 text-amber-900 dark:text-amber-200 print:hidden"
        data-test="report-problems"
    >
        <TriangleAlert class="size-4" aria-hidden="true" />
        <AlertTitle>This report looks broken</AlertTitle>
        <AlertDescription class="text-amber-900 dark:text-amber-200">
            <ul class="list-disc space-y-1 pl-4">
                <li
                    v-for="(problem, index) in problems"
                    :key="index"
                    class="break-words"
                    data-test="report-problem"
                >
                    {{ problem }}
                </li>
            </ul>
            <p class="mt-2">
                What the page shows may be incomplete. Run the scan again; if
                this keeps happening on this machine, the script may not work
                well here yet.
            </p>
        </AlertDescription>
    </Alert>
</template>
