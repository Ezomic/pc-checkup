<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

const page = usePage();

const appName = computed<string>(() => page.props.name);

// Registration is disabled in workflow mode, where Thijssensoftware ID
// provisions users, so the sign-up affordance must not be offered.
const workflowEnabled = computed<boolean>(
    () => page.props.workflow?.enabled ?? false,
);
</script>

<template>
    <!-- No title: app.ts already appends the app name to every page title, so
         setting it here renders "Web Template - Web Template". -->
    <Head />

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
                    <h1 class="text-xl font-medium">{{ appName }}</h1>
                </div>

                <div class="flex flex-col gap-3">
                    <Button as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <!-- Wayfinder does not generate @/routes/register in
                         workflow mode, because the route is never registered,
                         so this stays a literal path (WEB-7). -->
                    <Button v-if="!workflowEnabled" variant="outline" as-child>
                        <Link href="/register">Create an account</Link>
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
