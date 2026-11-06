<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed } from 'vue';
import BeforeAfterDocument from '@/components/share/BeforeAfterDocument.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { machine as showMachine } from '@/routes/share';
import type { BeforeAfter, SharedMachine } from '@/types';

const props = defineProps<{
    token: string;
    client: { name: string };
    machine: SharedMachine;
    comparison: BeforeAfter;
}>();

const { t } = useLocale();

const title = computed(
    () =>
        props.machine.name ?? props.machine.model ?? t('share.unnamedMachine'),
);

function print(): void {
    window.print();
}
</script>

<template>
    <Head :title="`${t('ba.title')} - ${title} - ${client.name}`" />

    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <Link
            :href="showMachine({ token, machine: machine.id })"
            class="flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline"
            data-test="back"
        >
            <ArrowLeft class="size-4" />
            {{ t('ba.back', { name: title }) }}
        </Link>
        <Button type="button" size="sm" data-test="print" @click="print">
            <Printer class="size-4" />
            {{ t('ba.print') }}
        </Button>
    </div>

    <BeforeAfterDocument
        :machine="machine"
        :client-name="client.name"
        :comparison="comparison"
    />
</template>
