<script setup lang="ts">
import { Download, Laptop, Monitor } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { download } from '@/routes/scripts';

type Platform = 'windows' | 'mac';

const commands: Record<Platform, string> = {
    windows: 'powershell -ExecutionPolicy Bypass -File windows-checkup.ps1',
    mac: 'bash mac-checkup.sh',
};

const platform = ref<Platform>('windows');
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap gap-2">
            <Button as-child @click="platform = 'windows'">
                <a :href="download('windows').url" data-test="download-windows">
                    <Monitor />
                    Windows
                    <Download />
                </a>
            </Button>
            <Button as-child variant="outline" @click="platform = 'mac'">
                <a :href="download('mac').url" data-test="download-mac">
                    <Laptop />
                    Mac
                    <Download />
                </a>
            </Button>
        </div>
        <p class="text-xs text-muted-foreground">
            Then run:
            <code
                class="rounded bg-muted px-1.5 py-0.5 font-mono"
                data-test="run-command"
                >{{ commands[platform] }}</code
            >
        </p>
    </div>
</template>
