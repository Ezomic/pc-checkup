<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/composables/useLocale';
import type { MachineStatus } from '@/types';

const props = defineProps<{
    status: MachineStatus;
}>();

const colours: Record<MachineStatus, string> = {
    ok: 'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400',
    needs_work:
        'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-400',
    reject: 'border-red-600/40 bg-red-600/15 text-red-700 dark:text-red-400',
};

const { t } = useLocale();

const label = computed(() => t(`status.${props.status}`));
</script>

<template>
    <Badge variant="outline" :class="colours[status]">{{ label }}</Badge>
</template>
