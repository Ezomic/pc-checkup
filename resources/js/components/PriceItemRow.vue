<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { editableEuro, formatEuro } from '@/lib/quote';
import { destroy, update } from '@/routes/price-items';
import type { PriceItem } from '@/types';

const props = defineProps<{
    item: PriceItem;
}>();

const editing = ref(false);
const form = useForm({
    name: props.item.name,
    price: editableEuro(props.item.price_cents),
    unit: props.item.unit ?? '',
});

function save(): void {
    form.put(update(props.item.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

function cancel(): void {
    form.reset();
    form.clearErrors();
    editing.value = false;
}

function remove(): void {
    router.delete(destroy(props.item.id).url, { preserveScroll: true });
}
</script>

<template>
    <li
        class="border-b border-border p-4 last:border-b-0"
        :data-test="`price-item-${item.id}`"
    >
        <form v-if="editing" class="grid gap-3" @submit.prevent="save">
            <Input
                v-model="form.name"
                maxlength="80"
                aria-label="Name"
                data-test="edit-name"
            />
            <InputError :message="form.errors.name" />
            <div class="flex gap-2">
                <Input
                    v-model="form.price"
                    inputmode="decimal"
                    aria-label="Price in euro, excluding VAT"
                    class="w-28"
                    data-test="edit-price"
                />
                <Input
                    v-model="form.unit"
                    maxlength="20"
                    placeholder="Unit (optional)"
                    aria-label="Unit"
                />
            </div>
            <InputError :message="form.errors.price" />
            <InputError :message="form.errors.unit" />
            <div class="flex gap-2">
                <Button type="submit" size="sm" :disabled="form.processing">
                    Save
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="cancel"
                >
                    Cancel
                </Button>
            </div>
        </form>

        <div v-else class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="font-medium break-words" data-test="item-name">
                    {{ item.name }}
                </p>
                <p class="text-sm text-muted-foreground">
                    EUR {{ formatEuro(item.price_cents) }}
                    <template v-if="item.unit"
                        >&middot; {{ item.unit }}</template
                    >
                </p>
            </div>
            <div class="flex shrink-0 gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    :aria-label="`Edit ${item.name}`"
                    data-test="edit"
                    @click="editing = true"
                >
                    <Pencil class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    :aria-label="`Delete ${item.name}`"
                    data-test="delete"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                </Button>
            </div>
        </div>
    </li>
</template>
