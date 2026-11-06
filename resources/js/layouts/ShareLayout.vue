<script setup lang="ts">
import LanguageSwitch from '@/components/share/LanguageSwitch.vue';
</script>

<template>
    <div class="min-h-svh bg-background text-foreground">
        <main
            class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 py-8 md:p-8"
        >
            <LanguageSwitch />
            <slot />
        </main>
    </div>
</template>
