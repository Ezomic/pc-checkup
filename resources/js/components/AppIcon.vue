<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    launchUrl: string;
    initials: string;
    accent: string | null;
}>();

// Try the app's own favicon (svg first for crispness, then ico); fall back to
// the initials tile when neither loads.
const candidates = computed<string[]>(() => {
    try {
        const origin = new URL(props.launchUrl).origin;

        return [`${origin}/favicon.svg`, `${origin}/favicon.ico`];
    } catch {
        return [];
    }
});

const index = ref(0);
const exhausted = ref(false);

watch(candidates, () => {
    index.value = 0;
    exhausted.value = false;
});

const src = computed<string | null>(
    () => candidates.value[index.value] ?? null,
);
const showIcon = computed(() => !exhausted.value && src.value !== null);

function onError(): void {
    if (index.value < candidates.value.length - 1) {
        index.value += 1;

        return;
    }

    exhausted.value = true;
}

// Some apps serve an empty 200 for favicon.ico, which fires `load`, not
// `error`. Treat a zero-dimension image as a miss so the fallback still kicks in.
function onLoad(event: Event): void {
    if ((event.target as HTMLImageElement).naturalWidth === 0) {
        onError();
    }
}
</script>

<template>
    <span
        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg"
        :class="showIcon ? 'bg-muted' : 'text-base font-semibold text-white'"
        :style="showIcon ? {} : { backgroundColor: accent ?? '#6b7280' }"
    >
        <img
            v-if="showIcon"
            :src="src!"
            alt=""
            class="size-full object-contain p-1.5"
            @error="onError"
            @load="onLoad"
        />
        <template v-else>{{ initials }}</template>
    </span>
</template>
