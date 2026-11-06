<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { AttentionLevel } from '@/types';

defineProps<{
    level: AttentionLevel | 'fine';
}>();

const labels: Record<AttentionLevel | 'fine', string> = {
    urgent: 'Urgent',
    soon: 'Soon',
    fine: 'Fine',
};

const colours: Record<AttentionLevel | 'fine', string> = {
    urgent: 'border-red-600/40 bg-red-600/15 text-red-700 dark:text-red-400',
    soon: 'border-amber-500/40 bg-amber-500/15 text-amber-800 dark:text-amber-300',
    fine: 'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400',
};
</script>

<template>
    <Badge variant="outline" :class="colours[level]" data-test="level-badge">
        {{ labels[level] }}
    </Badge>
</template>
