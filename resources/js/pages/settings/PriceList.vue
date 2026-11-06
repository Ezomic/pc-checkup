<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PriceItemRow from '@/components/PriceItemRow.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/price-items';
import type { PriceItem } from '@/types';

defineProps<{
    items: PriceItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Price list', href: index() }],
    },
});
</script>

<template>
    <Head title="Price list" />

    <h1 class="sr-only">Price list</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Price list"
            description="Your own prices, excluding VAT. They feed the Estimate on a machine page. Only you can see them."
        />

        <Form
            v-bind="store.form()"
            reset-on-success
            class="space-y-4"
            #default="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    maxlength="80"
                    placeholder="e.g. New battery"
                    required
                />
                <InputError :message="errors.name" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="price">Price in euro</Label>
                    <Input
                        id="price"
                        name="price"
                        inputmode="decimal"
                        placeholder="12,50"
                        required
                    />
                    <InputError :message="errors.price" />
                </div>
                <div class="grid gap-2">
                    <Label for="unit">Unit (optional)</Label>
                    <Input
                        id="unit"
                        name="unit"
                        maxlength="20"
                        placeholder="per hour"
                    />
                    <InputError :message="errors.unit" />
                </div>
            </div>
            <Button type="submit" :disabled="processing">
                <Spinner v-if="processing" />
                Add item
            </Button>
        </Form>

        <div class="overflow-hidden rounded-lg border border-border">
            <p
                v-if="items.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
                data-test="price-list-empty"
            >
                No price items yet.
            </p>
            <ul v-else>
                <PriceItemRow
                    v-for="item in items"
                    :key="item.id"
                    :item="item"
                />
            </ul>
        </div>
    </div>
</template>
