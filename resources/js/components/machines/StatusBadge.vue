<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { MachineStatus } from '@/types';

const props = defineProps<{
    status: MachineStatus;
}>();

const labels: Record<MachineStatus, string> = {
    checking: 'Checking',
    ready: 'Ready',
    needs_work: 'Needs work',
};

const variants = {
    checking: 'outline',
    ready: 'default',
    needs_work: 'destructive',
} as const;

const label = computed(() => labels[props.status]);
</script>

<template>
    <Badge :variant="variants[status]">{{ label }}</Badge>
</template>
