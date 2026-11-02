<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { destroy, store } from '@/routes/clients/share-link';

const props = defineProps<{
    clientId: number;
    shareUrl: string | null;
}>();

const input = ref<HTMLInputElement | null>(null);
const copied = ref(false);

async function copy(): Promise<void> {
    if (props.shareUrl === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.shareUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        input.value?.select();
    }
}

function generate(): void {
    router.post(store(props.clientId).url, {}, { preserveScroll: true });
}

function regenerate(): void {
    if (
        window.confirm(
            'Make a new share link? The current link stops working straight away.',
        )
    ) {
        generate();
    }
}

function revoke(): void {
    if (
        window.confirm(
            'Revoke the share link? Anyone using it will lose access.',
        )
    ) {
        router.delete(destroy(props.clientId).url, { preserveScroll: true });
    }
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="share-link">
        <div class="flex items-center gap-2">
            <h2 class="text-lg font-semibold">Share link</h2>
            <Badge
                variant="outline"
                :class="
                    shareUrl === null
                        ? 'text-muted-foreground'
                        : 'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400'
                "
            >
                {{ shareUrl === null ? 'Off' : 'Active' }}
            </Badge>
        </div>

        <template v-if="shareUrl === null">
            <p class="text-sm text-muted-foreground">
                There is no share link. Create one to give this client a page
                with their machines.
            </p>
            <div>
                <Button size="sm" data-test="create-link" @click="generate">
                    Create share link
                </Button>
            </div>
        </template>

        <template v-else>
            <div class="flex max-w-xl items-center gap-2">
                <input
                    ref="input"
                    :value="shareUrl"
                    readonly
                    aria-label="Share link"
                    class="h-9 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @focus="input?.select()"
                />
                <Button
                    size="sm"
                    variant="outline"
                    data-test="copy-link"
                    @click="copy"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    data-test="regenerate-link"
                    @click="regenerate"
                >
                    Make a new link
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    data-test="revoke-link"
                    @click="revoke"
                >
                    Revoke link
                </Button>
            </div>
        </template>
    </section>
</template>
