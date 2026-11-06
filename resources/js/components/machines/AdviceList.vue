<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AdviceItem from '@/components/machines/AdviceItem.vue';
import { useLocale } from '@/composables/useLocale';
import type { SharedAdvice } from '@/types';

const FIRST = 3;

const props = defineProps<{
    advice: SharedAdvice[];
}>();

const { t } = useLocale();

const first = computed(() => props.advice.slice(0, FIRST));
const rest = computed(() => props.advice.slice(FIRST));
const folded = ref<HTMLDetailsElement | null>(null);
let reopen = false;

function openForPrint(): void {
    if (folded.value && !folded.value.open) {
        folded.value.open = true;
        reopen = true;
    }
}

function restoreAfterPrint(): void {
    if (folded.value && reopen) {
        folded.value.open = false;
    }

    reopen = false;
}

onMounted(() => {
    window.addEventListener('beforeprint', openForPrint);
    window.addEventListener('afterprint', restoreAfterPrint);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeprint', openForPrint);
    window.removeEventListener('afterprint', restoreAfterPrint);
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul class="flex flex-col gap-2">
            <AdviceItem
                v-for="(item, index) in first"
                :key="index"
                :item="item"
            />
        </ul>

        <details v-if="rest.length > 0" ref="folded" class="text-sm">
            <summary class="cursor-pointer text-muted-foreground print:hidden">
                {{ t('advice.more', { n: rest.length }) }}
            </summary>
            <ul class="mt-2 flex flex-col gap-2">
                <AdviceItem
                    v-for="(item, index) in rest"
                    :key="FIRST + index"
                    :item="item"
                />
            </ul>
        </details>
    </div>
</template>
