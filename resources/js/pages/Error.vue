<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const props = defineProps<{
    status: number;
}>();

type ErrorCopy = {
    title: string;
    description: string;
};

const copy: Record<number, ErrorCopy> = {
    403: {
        title: 'Forbidden',
        description: 'You do not have permission to view this page.',
    },
    404: {
        title: 'Page not found',
        description:
            'This page does not exist, or it has moved somewhere else.',
    },
    419: {
        title: 'Page expired',
        description:
            'Your session timed out for security. Reload the page and try again.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'An unexpected error occurred on our side. It has been logged and we are looking into it.',
    },
};

const fallback: ErrorCopy = {
    title: 'Something went wrong',
    description: 'The server could not complete that request.',
};

const current = computed<ErrorCopy>(() => copy[props.status] ?? fallback);

const page = usePage();
const isAuthenticated = computed<boolean>(() => Boolean(page.props.auth?.user));

function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="`${status} ${current.title}`" />

    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <div
                        class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                    >
                        <AppLogoIcon
                            class="size-9 fill-current text-[var(--foreground)] dark:text-white"
                        />
                    </div>
                    <div class="space-y-2 text-center">
                        <p
                            class="text-sm font-medium tracking-widest text-muted-foreground"
                        >
                            {{ status }}
                        </p>
                        <h1 class="text-xl font-medium">{{ current.title }}</h1>
                        <p class="text-center text-sm text-muted-foreground">
                            {{ current.description }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-3">
                    <Button v-if="status === 419" @click="reload">
                        Reload the page
                    </Button>
                    <Button
                        :variant="status === 419 ? 'outline' : 'default'"
                        as-child
                    >
                        <Link :href="isAuthenticated ? dashboard() : login()">
                            {{
                                isAuthenticated
                                    ? 'Back to dashboard'
                                    : 'Go to login'
                            }}
                        </Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
