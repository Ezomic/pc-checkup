<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Check, Copy, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { destroy, index, store } from '@/routes/api-tokens';
import type { ApiToken } from '@/types';

defineProps<{
    tokens: ApiToken[];
    createdToken: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'API tokens', href: index() }],
    },
});

const copied = ref(false);

async function copy(token: string): Promise<void> {
    await navigator.clipboard.writeText(token);
    copied.value = true;

    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}

function revoke(id: number): void {
    router.delete(destroy(id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="API tokens"
            description="Tokens authenticate as you against the API. Treat them like passwords."
        />

        <!-- Rendered from a session flash, so it is here for exactly one render.
             Only a hash is stored, so this is the only chance to copy it. -->
        <Alert v-if="createdToken">
            <AlertTitle>Copy your token now</AlertTitle>
            <AlertDescription class="space-y-3">
                <p>This is the only time it will be shown.</p>
                <div class="flex w-full items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md bg-muted px-3 py-2 font-mono text-xs"
                        data-testid="created-token"
                    >
                        {{ createdToken }}
                    </code>
                    <Button
                        size="sm"
                        variant="outline"
                        :aria-label="copied ? 'Copied' : 'Copy token'"
                        @click="copy(createdToken)"
                    >
                        <Check v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </AlertDescription>
        </Alert>

        <Form
            v-bind="store.form()"
            reset-on-success
            class="flex items-end gap-2"
            #default="{ errors, processing }"
        >
            <div class="grid flex-1 gap-2">
                <Label for="name">Token name</Label>
                <Input
                    id="name"
                    name="name"
                    placeholder="e.g. Laptop CLI"
                    required
                />
                <InputError :message="errors.name" />
            </div>
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                Create token
            </Button>
        </Form>

        <div class="overflow-hidden rounded-lg border border-border">
            <p
                v-if="tokens.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No tokens yet.
            </p>

            <div
                v-for="token in tokens"
                v-else
                :key="token.id"
                class="flex items-center justify-between gap-4 border-b border-border p-4 last:border-b-0"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ token.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        Created {{ token.created_at_diff }} &middot;
                        {{
                            token.last_used_at_diff
                                ? `last used ${token.last_used_at_diff}`
                                : 'never used'
                        }}
                        <template v-if="token.expires_at_diff">
                            &middot;
                            <span
                                :class="{ 'text-destructive': token.expired }"
                            >
                                {{ token.expired ? 'expired' : 'expires' }}
                                {{ token.expires_at_diff }}
                            </span>
                        </template>
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    :aria-label="`Revoke ${token.name}`"
                    @click="revoke(token.id)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
