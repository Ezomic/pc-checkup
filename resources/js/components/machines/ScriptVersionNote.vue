<script setup lang="ts">
import { computed } from 'vue';
import type { ReportScript } from '@/types';

const props = defineProps<{ script: ReportScript }>();

const label = computed(() => props.script.version ?? 'unknown');

const note = computed(() => {
    const again =
        'Download the script again and rerun to get the newest checks.';

    return props.script.version === null
        ? `This scan came from an unknown script version, probably an old one (current ${props.script.current}). ${again}`
        : `This scan came from an older script (version ${props.script.version}, current ${props.script.current}). ${again}`;
});
</script>

<template>
    <div class="flex flex-col gap-1 print:hidden" data-test="script-version">
        <p
            class="text-xs text-muted-foreground"
            data-test="script-version-line"
        >
            Script version: {{ label }}
        </p>
        <p
            v-if="script.outdated"
            class="text-sm font-medium text-amber-800 dark:text-amber-300"
            data-test="script-outdated"
        >
            {{ note }}
        </p>
        <p
            v-if="script.ranOutdated"
            class="text-sm text-muted-foreground"
            data-test="script-ran-outdated"
        >
            The script warned that a newer version existed, and the scan was run
            anyway.
        </p>
    </div>
</template>
