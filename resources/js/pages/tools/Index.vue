<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Copy, Download } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/tools';
import { download } from '@/routes/tools/rustdesk';
import type { RustDeskTool } from '@/types';

defineProps<{
    rustdesk: RustDeskTool;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tools', href: index() }],
    },
});

const command =
    "powershell -ExecutionPolicy Bypass -File rustdesk-install.ps1 -Password 'choose-a-password'";

const copied = ref(false);

async function copy(url: string): Promise<void> {
    await navigator.clipboard.writeText(url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <Head title="Tools" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 pt-8">
        <div>
            <h1 class="text-2xl font-semibold">Tools</h1>
            <p class="text-sm text-muted-foreground">
                Small helpers for the machines you look after.
            </p>
        </div>

        <section
            class="flex max-w-2xl flex-col gap-4 rounded-xl border border-border bg-card p-5"
        >
            <div>
                <h2 class="text-lg font-medium">
                    RustDesk installer (Windows)
                </h2>
                <p class="text-sm text-muted-foreground">
                    Installs RustDesk without prompts, points it at your own
                    server and turns on unattended access. Run it as
                    administrator.
                </p>
            </div>

            <p
                v-if="!rustdesk.configured"
                class="rounded-lg bg-muted p-3 text-sm"
                data-test="not-configured"
            >
                Not set up yet. Set RUSTDESK_HOST and RUSTDESK_KEY (and
                RUSTDESK_RELAY if the relay is a different host) on the server.
            </p>

            <template v-else>
                <div class="flex flex-wrap gap-2">
                    <Button as-child>
                        <a :href="download().url" data-test="download">
                            <Download />
                            Download installer
                        </a>
                    </Button>
                </div>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium">Temporary link</p>
                    <p class="text-sm text-muted-foreground">
                        Works without signing in for {{ rustdesk.link_minutes }}
                        minutes. Use it to fetch the installer on the other
                        machine.
                    </p>
                    <div class="flex gap-2">
                        <input
                            readonly
                            :value="rustdesk.temporary_url ?? ''"
                            class="min-w-0 flex-1 rounded-md border border-input bg-background px-3 py-2 font-mono text-xs"
                            data-test="temporary-url"
                        />
                        <Button
                            variant="outline"
                            @click="copy(rustdesk.temporary_url ?? '')"
                        >
                            <Check v-if="copied" />
                            <Copy v-else />
                            {{ copied ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                </div>

                <p class="text-xs text-muted-foreground">
                    Then run:
                    <code
                        class="rounded bg-muted px-1.5 py-0.5 font-mono break-all"
                        data-test="run-command"
                        >{{ command }}</code
                    >
                    The password is never stored in the script. Choose your own
                    and keep it somewhere safe.
                </p>
            </template>
        </section>
    </div>
</template>
