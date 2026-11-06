<script setup lang="ts">
import { Check, Copy, ExternalLink } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { Warranty } from '@/types';

const props = defineProps<{
    warranty: Warranty;
}>();

const copied = ref(false);

async function copy(): Promise<void> {
    await navigator.clipboard.writeText(props.warranty.serial);
    copied.value = true;

    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="warranty-section">
        <h2 class="text-lg font-semibold">Warranty</h2>

        <div class="flex flex-wrap items-center gap-3">
            <p class="text-sm">
                {{ warranty.maker }} serial
                <span
                    class="font-mono font-medium"
                    data-test="warranty-serial"
                    >{{ warranty.serial }}</span
                >
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                aria-label="Copy serial"
                data-test="copy-serial"
                @click="copy"
            >
                <Check v-if="copied" class="size-4" />
                <Copy v-else class="size-4" />
                {{ copied ? 'Copied' : 'Copy' }}
            </Button>
            <Button as-child size="sm" data-test="check-warranty">
                <a
                    :href="warranty.url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="size-4" />
                    Check warranty
                </a>
            </Button>
        </div>

        <p
            v-if="!warranty.serial_in_url"
            class="text-xs text-muted-foreground"
            data-test="paste-hint"
        >
            {{ warranty.maker }} cannot take the serial in the link. Copy it and
            paste it on their page.
        </p>
        <p class="text-xs text-muted-foreground">
            This is a plain link. Nothing is sent from this app to
            {{ warranty.maker }}.
        </p>
    </section>
</template>
