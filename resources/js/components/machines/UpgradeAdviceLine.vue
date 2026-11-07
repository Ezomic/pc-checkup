<script setup lang="ts">
import { computed } from 'vue';
import { formatEuro } from '@/lib/quote';
import type { UpgradeAdvice } from '@/types';

const props = defineProps<{ advice: UpgradeAdvice }>();

const priceLine = computed(() => {
    const price = props.advice.price;

    if (price === null) {
        return null;
    }

    const unit = price.unit ? ` (${price.unit})` : '';

    return `From your price list: ${price.name}${unit}, EUR ${formatEuro(price.price_cents)} excl. VAT`;
});
</script>

<template>
    <div
        class="flex min-w-0 flex-col gap-1 rounded-lg border border-border p-4 print:hidden"
        data-test="upgrade-advice"
    >
        <p class="text-sm text-muted-foreground">Upgrade advice</p>
        <p class="font-medium break-words" data-test="upgrade-advice-text">
            {{ advice.text }}
        </p>
        <p
            v-if="priceLine"
            class="text-sm break-words text-muted-foreground"
            data-test="upgrade-advice-price"
        >
            {{ priceLine }}
        </p>
    </div>
</template>
