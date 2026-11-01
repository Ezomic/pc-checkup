<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import CheckupDownloads from '@/components/CheckupDownloads.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const page = usePage();

const appName = computed<string>(() => page.props.name);
const signedIn = computed<boolean>(() => page.props.auth.user !== null);

const sample = [
    { label: 'Battery', value: 'OK', tone: 'ok' },
    { label: 'Disk health', value: 'OK', tone: 'ok' },
    { label: 'Pending updates', value: '6 flags', tone: 'warn' },
    { label: 'Checklist', value: '0 of 18', tone: 'none' },
] as const;

const tones = {
    ok: 'text-green-600 dark:text-green-400',
    warn: 'text-amber-600 dark:text-amber-400',
    none: 'text-muted-foreground',
};
</script>

<template>
    <!-- No title: app.ts already appends the app name to every page title, so
         setting it here renders "Web Template - Web Template". -->
    <Head />

    <div class="flex min-h-svh flex-col bg-background">
        <header
            class="flex items-center justify-between border-b px-6 py-4 md:px-10"
        >
            <div class="flex items-center gap-2">
                <AppLogoIcon
                    class="size-6 fill-current text-[var(--foreground)] dark:text-white"
                />
                <span class="font-medium">{{ appName }}</span>
            </div>
            <Button as-child variant="outline" size="sm">
                <Link v-if="signedIn" :href="dashboard()">Open app</Link>
                <Link v-else :href="login()">Log in</Link>
            </Button>
        </header>

        <main
            class="mx-auto grid w-full max-w-5xl flex-1 items-center gap-10 px-6 py-12 md:grid-cols-2 md:px-10"
        >
            <section class="flex flex-col gap-5">
                <h1 class="text-3xl font-semibold tracking-tight md:text-4xl">
                    Check every PC before it changes hands
                </h1>
                <p class="text-muted-foreground">
                    Run one read-only script on the PC. Get battery, disk,
                    drivers and security in a single report, then track the
                    checklist, photos and status per machine.
                </p>
                <CheckupDownloads />
                <p class="text-xs text-muted-foreground">
                    Nothing is changed on the PC, and no admin rights are
                    needed.
                </p>
            </section>

            <section
                class="rounded-xl border bg-card p-5 shadow-xs"
                aria-label="Sample report"
            >
                <div class="mb-3 flex items-center justify-between">
                    <span class="font-medium">THIJSSEN</span>
                    <span
                        class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200"
                        >Needs work</span
                    >
                </div>
                <ul class="divide-y text-sm">
                    <li
                        v-for="row in sample"
                        :key="row.label"
                        class="flex justify-between py-2"
                    >
                        <span>{{ row.label }}</span>
                        <span :class="tones[row.tone]">{{ row.value }}</span>
                    </li>
                </ul>
            </section>
        </main>
    </div>
</template>
