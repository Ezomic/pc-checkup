<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'This link is no longer valid',
        description:
            'It may have expired or already been used. Request a new one from the login page.',
    },
});
</script>

<template>
    <Head title="Link no longer valid" />

    <div class="text-center text-sm">
        <TextLink :href="login()">Back to log in</TextLink>
    </div>
</template>
