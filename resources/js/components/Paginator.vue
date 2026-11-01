<script setup lang="ts" generic="T">
import { router } from '@inertiajs/vue3';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import type { Paginated } from '@/types';

const props = defineProps<{
    meta: Paginated<T>;
}>();

// Rebuilt from the current query string rather than from meta.links, so
// filters and sorts on the page survive a page change.
function urlFor(page: number): string {
    const url = new URL(props.meta.path, window.location.origin);
    const params = new URLSearchParams(window.location.search);

    params.set('page', String(page));
    url.search = params.toString();

    return url.toString();
}

function go(page: number): void {
    router.get(urlFor(page), {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div
        v-if="meta.last_page > 1"
        class="flex flex-col items-center justify-between gap-3 sm:flex-row"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ meta.from }} to {{ meta.to }} of {{ meta.total }}
        </p>

        <Pagination
            :total="meta.total"
            :items-per-page="meta.per_page"
            :page="meta.current_page"
            :sibling-count="1"
            show-edges
            class="mx-0 w-auto justify-end"
            @update:page="go"
        >
            <PaginationContent v-slot="{ items }">
                <PaginationPrevious />

                <template v-for="(item, index) in items">
                    <PaginationItem
                        v-if="item.type === 'page'"
                        :key="`page-${item.value}`"
                        :value="item.value"
                        :is-active="item.value === meta.current_page"
                    >
                        {{ item.value }}
                    </PaginationItem>
                    <PaginationEllipsis v-else :key="`ellipsis-${index}`" />
                </template>

                <PaginationNext />
            </PaginationContent>
        </Pagination>
    </div>
</template>
