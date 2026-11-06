<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { buildQuote, formatEuro, quoteTotals, suggestedIds } from '@/lib/quote';
import { index } from '@/routes/price-items';
import type { Estimate, PriceItem } from '@/types';

const props = defineProps<{
    machineTitle: string;
    items: PriceItem[];
    estimate: Estimate | null;
    vatPercent: number;
}>();

const selected = ref<Set<number>>(
    new Set(suggestedIds(props.items, props.estimate)),
);
const includeVat = ref(true);
const copied = ref(false);

const chosen = computed(() =>
    props.items.filter((entry) => selected.value.has(entry.id)),
);
const totals = computed(() => quoteTotals(chosen.value, props.vatPercent));
const quote = computed(() =>
    buildQuote(
        props.machineTitle,
        chosen.value,
        includeVat.value,
        props.vatPercent,
    ),
);

function toggle(id: number): void {
    const next = new Set(selected.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    selected.value = next;
}

async function copy(): Promise<void> {
    await navigator.clipboard.writeText(quote.value);
    copied.value = true;

    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="estimate-section">
        <h2 class="text-lg font-semibold">Estimate</h2>

        <p
            v-if="items.length === 0"
            class="text-sm text-muted-foreground"
            data-test="estimate-empty"
        >
            You have no price items yet.
            <Link :href="index()" class="underline underline-offset-4"
                >Add your prices</Link
            >
            to build a quote here.
        </p>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                <template v-if="estimate">
                    The verdict is {{ estimate.verdict }}. Items that fit the
                    reasons are ticked to start with.
                </template>
                <template v-else>Tick the items for this machine.</template>
                Prices in your list are taken as excluding VAT.
            </p>

            <ul class="flex flex-col gap-1" data-test="estimate-items">
                <li v-for="entry in items" :key="entry.id">
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 text-sm"
                    >
                        <input
                            type="checkbox"
                            class="mt-0.5 size-4 shrink-0 accent-primary"
                            :checked="selected.has(entry.id)"
                            :data-test="`item-${entry.id}`"
                            @change="toggle(entry.id)"
                        />
                        <span class="min-w-0 flex-1 break-words">
                            {{ entry.name }}
                            <span
                                v-if="entry.unit"
                                class="text-muted-foreground"
                                >({{ entry.unit }})</span
                            >
                        </span>
                        <span class="shrink-0 font-mono tabular-nums"
                            >EUR {{ formatEuro(entry.price_cents) }}</span
                        >
                    </label>
                </li>
            </ul>

            <label class="flex items-center gap-2 text-sm">
                <input
                    v-model="includeVat"
                    type="checkbox"
                    class="size-4 accent-primary"
                    data-test="include-vat"
                />
                Show the total including {{ vatPercent }}% VAT
            </label>

            <p class="text-lg font-semibold" data-test="estimate-total">
                Total: EUR
                {{ formatEuro(includeVat ? totals.incl : totals.excl) }}
                {{ includeVat ? 'incl.' : 'excl.' }} VAT
            </p>

            <pre
                v-if="chosen.length > 0"
                class="max-h-64 overflow-auto rounded-lg bg-muted p-3 text-xs break-words whitespace-pre-wrap"
                data-test="quote-text"
                >{{ quote }}</pre>

            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="chosen.length === 0"
                    data-test="copy-quote"
                    @click="copy"
                >
                    <Check v-if="copied" class="size-4" />
                    <Copy v-else class="size-4" />
                    {{ copied ? 'Copied' : 'Copy quote' }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                This is a calculator. The ticked items are not saved with the
                machine.
            </p>
        </template>
    </section>
</template>
