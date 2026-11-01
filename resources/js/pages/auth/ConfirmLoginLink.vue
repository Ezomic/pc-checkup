<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { consume } from '@/routes/login/link';

defineOptions({
    layout: {
        title: 'Continue to PC Checkup',
        description: 'Confirm to finish logging in with your emailed link.',
    },
});

defineProps<{
    token: string;
    email: string;
}>();
</script>

<template>
    <Head title="Continue to PC Checkup" />

    <Form
        v-bind="consume.form(token)"
        class="flex flex-col gap-6"
        v-slot="{ processing }"
    >
        <p class="text-center text-sm" data-test="login-link-email">
            You will be logged in as {{ email }}
        </p>
        <Button
            type="submit"
            class="w-full"
            :disabled="processing"
            data-test="confirm-login-link-button"
        >
            <Spinner v-if="processing" />
            Continue to PC Checkup
        </Button>
    </Form>
</template>
