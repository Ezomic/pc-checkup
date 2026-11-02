<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/machines/status';
import type { MachineStatus } from '@/types';

const props = defineProps<{
    machineId: number;
    status: MachineStatus;
}>();

const options: { value: MachineStatus; label: string }[] = [
    { value: 'ok', label: 'Looking good' },
    { value: 'needs_work', label: 'Needs work' },
    { value: 'reject', label: 'Reject' },
];

function choose(status: MachineStatus): void {
    if (status === props.status) {
        return;
    }

    router.put(
        update(props.machineId).url,
        { status },
        { preserveScroll: true },
    );
}
</script>

<template>
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Status</h2>
        <div class="flex flex-wrap gap-1">
            <Button
                v-for="option in options"
                :key="option.value"
                type="button"
                size="sm"
                :variant="status === option.value ? 'default' : 'outline'"
                :aria-pressed="status === option.value"
                @click="choose(option.value)"
            >
                {{ option.label }}
            </Button>
        </div>
    </section>
</template>
