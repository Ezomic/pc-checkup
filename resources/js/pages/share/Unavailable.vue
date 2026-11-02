<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status: number;
}>();

const missing = computed(() => props.status === 404);
</script>

<template>
    <Head title="Link not available" />

    <div class="flex flex-col gap-2 py-16 text-center">
        <p class="text-sm font-medium tracking-widest text-muted-foreground">
            {{ status }}
        </p>
        <h1 class="text-xl font-medium">
            {{
                missing ? 'This link is not available' : 'Something went wrong'
            }}
        </h1>
        <p class="text-sm text-muted-foreground">
            {{
                missing
                    ? 'It may have been replaced or switched off. Ask whoever sent it to you for a new one.'
                    : 'Please try again in a moment.'
            }}
        </p>
    </div>
</template>
