<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import Paginator from '@/components/Paginator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/clients';
import type { ClientSummary, Paginated } from '@/types';

defineProps<{
    clients: Paginated<ClientSummary>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Clients', href: index() }],
    },
});
</script>

<template>
    <Head title="Clients" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 pt-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Clients</h1>
                <p class="text-sm text-muted-foreground">
                    The people whose machines you look after.
                </p>
            </div>
            <Button as-child size="sm">
                <Link :href="create()">
                    <Plus class="size-4" />
                    New client
                </Link>
            </Button>
        </div>

        <p
            v-if="clients.data.length === 0"
            class="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
        >
            No clients yet. Add one to group machines and share a link.
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li v-for="client in clients.data" :key="client.id">
                <Link
                    :href="show(client.id)"
                    class="flex items-center justify-between gap-4 rounded-xl border border-border p-4 transition-colors hover:bg-muted/50"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ client.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ client.machines_count }}
                            {{
                                client.machines_count === 1
                                    ? 'machine'
                                    : 'machines'
                            }}
                        </p>
                    </div>
                    <Badge
                        variant="outline"
                        :class="
                            client.has_share_link
                                ? 'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            client.has_share_link
                                ? 'Share link active'
                                : 'No share link'
                        }}
                    </Badge>
                </Link>
            </li>
        </ul>

        <Paginator :meta="clients" />
    </div>
</template>
