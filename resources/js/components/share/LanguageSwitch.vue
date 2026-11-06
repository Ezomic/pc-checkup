<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
import { LOCALES } from '@/lib/messages';

const { locale, t } = useLocale();
</script>

<template>
    <nav
        :aria-label="t('switch.label')"
        class="flex justify-end gap-1 text-sm print:hidden"
        data-test="language-switch"
    >
        <a
            v-for="option in LOCALES"
            :key="option"
            :href="`?lang=${option}`"
            :lang="option"
            :hreflang="option"
            :aria-current="option === locale ? 'true' : undefined"
            class="rounded-md px-2 py-1 transition-colors"
            :class="
                option === locale
                    ? 'bg-muted font-medium text-foreground'
                    : 'text-muted-foreground underline-offset-4 hover:underline'
            "
            :data-test="`lang-${option}`"
        >
            {{ t(`switch.${option}`) }}
        </a>
    </nav>
</template>
