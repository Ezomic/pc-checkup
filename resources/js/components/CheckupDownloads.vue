<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Download, Laptop, Monitor } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { download } from '@/routes/scripts';

type Platform = 'windows' | 'mac';

const page = usePage();

const platform = ref<Platform>('windows');

const file = computed<string>(() => {
    const { windows, mac } = page.props.scriptVersions;

    return platform.value === 'windows'
        ? `windows-checkup-${windows}.ps1`
        : `mac-checkup-${mac}.sh`;
});

const command = computed<string>(() =>
    platform.value === 'windows'
        ? `powershell -ExecutionPolicy Bypass -File ${file.value}`
        : `bash ${file.value}`,
);
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
                >{{ command }}</code
            >
        </p>
        <p class="text-xs text-muted-foreground" data-test="run-hint">
            The download is named
            <span class="font-mono">{{ file }}</span
            >. Delete older copies of the script first, so an old one cannot run
            by mistake.
        </p>
    </div>
</template>
