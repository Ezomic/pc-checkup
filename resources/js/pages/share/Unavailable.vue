<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';

const props = defineProps<{
    status: number;
}>();

const { t } = useLocale();

const missing = computed(() => props.status === 404);
</script>

<template>
    <Head :title="t('share.unavailableTitle')" />

    <div class="flex flex-col gap-2 py-16 text-center">
        <p class="text-sm font-medium tracking-widest text-muted-foreground">
            {{ status }}
        </p>
        <h1 class="text-xl font-medium">
            {{ t(missing ? 'share.missingHeading' : 'share.errorHeading') }}
        </h1>
        <p class="text-sm text-muted-foreground">
            {{ t(missing ? 'share.missingBody' : 'share.errorBody') }}
        </p>
    </div>
</template>
