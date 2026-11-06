<script setup lang="ts">
import LanguageSwitch from '@/components/share/LanguageSwitch.vue';
import { useLocale } from '@/composables/useLocale';
import { privacy } from '@/routes';

const { locale, t } = useLocale();
</script>

<template>
    <div class="min-h-svh bg-background text-foreground">
        <main
            class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 py-8 md:p-8"
        >
            <LanguageSwitch />
            <slot />
            <footer class="border-t border-border pt-4 text-xs print:hidden">
                <a
                    :href="privacy({ query: { lang: locale } }).url"
                    class="text-muted-foreground underline underline-offset-4"
                    data-test="privacy-link"
                    >{{ t('privacy.link') }}</a
                >
            </footer>
        </main>
    </div>
</template>
