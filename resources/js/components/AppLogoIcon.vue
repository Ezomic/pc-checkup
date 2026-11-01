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
    A monitor with a check, the same shape as public/favicon.svg so the tab and
    the sidebar agree. Every caller passes `fill-current`, so it must be a filled
    mark rather than a stroked one.
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
            d="M4 3h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v10h16V5H4Zm3 5.4 1.3-1.3 2.2 2.2 5.2-5.2L17 7.4l-6.5 6.5L7 10.4ZM11 17h2v2h-2v-2Zm-3 2h8a1 1 0 0 1 0 2H8a1 1 0 0 1 0-2Z"
        />
    </svg>
</template>
