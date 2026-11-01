<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<!--
    A placeholder mark, on purpose. It used to be Laravel's logo, which is how
    three apps ended up serving their own favicon in the browser tab while the
    sidebar still showed Laravel's.

    Replace the paths below with the app's own mark, drawn from the same shape as
    its favicon so the tab and the sidebar agree. Every caller passes
    `fill-current`, so it must be a filled mark rather than a stroked one.
-->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M6 2h12a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V6a4 4 0 0 1 4-4Zm0 2.5A1.5 1.5 0 0 0 4.5 6v12A1.5 1.5 0 0 0 6 19.5h12a1.5 1.5 0 0 0 1.5-1.5V6A1.5 1.5 0 0 0 18 4.5H6Z"
        />
        <path fill="currentColor" d="M12 9a3 3 0 1 1 0 6 3 3 0 0 1 0-6Z" />
    </svg>
</template>
