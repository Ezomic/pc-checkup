<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MessageKey } from '@/lib/messages';
import { download } from '@/routes/scripts';

const props = defineProps<{
    ownerName: string;
    contactEmail: string;
    contactConfigured: boolean;
    scripts: { os: 'windows' | 'mac'; version: string }[];
}>();

const { t } = useLocale();

const sections: { heading: MessageKey; body: MessageKey }[] = [
    { heading: 'privacy.readsHeading', body: 'privacy.reads' },
    { heading: 'privacy.notReadHeading', body: 'privacy.notRead' },
    { heading: 'privacy.changesHeading', body: 'privacy.changes' },
    { heading: 'privacy.keptHeading', body: 'privacy.kept' },
    { heading: 'privacy.deleteHeading', body: 'privacy.delete' },
];

const links = computed(() =>
    props.scripts.map((script) => ({
        os: script.os,
        label: t(
            script.os === 'windows'
                ? 'privacy.scriptWindows'
                : 'privacy.scriptMac',
        ),
        version: t('privacy.scriptVersion', { version: script.version }),
        href: download(script.os).url,
    })),
);
</script>

<template>
    <Head :title="t('privacy.title')" />

    <h1 class="text-2xl font-semibold" data-test="privacy-title">
        {{ t('privacy.title') }}
    </h1>

    <section
        v-for="section in sections"
        :key="section.heading"
        class="flex flex-col gap-1"
    >
        <h2 class="text-base font-semibold">{{ t(section.heading) }}</h2>
        <p class="text-sm">{{ t(section.body) }}</p>
    </section>

    <section class="flex flex-col gap-1" data-test="privacy-owner">
        <h2 class="text-base font-semibold">{{ t('privacy.ownerHeading') }}</h2>
        <p class="text-sm">
            {{ t('privacy.owner', { name: ownerName }) }}
            <a
                v-if="contactConfigured"
                :href="`mailto:${contactEmail}`"
                class="underline underline-offset-4"
                data-test="privacy-email"
                >{{ contactEmail }}</a
            >
            <span v-else data-test="privacy-email">{{ contactEmail }}</span>
        </p>
    </section>

    <section class="flex flex-col gap-1">
        <h2 class="text-base font-semibold">
            {{ t('privacy.scriptHeading') }}
        </h2>
        <p class="text-sm">{{ t('privacy.script') }}</p>
        <ul class="text-sm">
            <li v-for="link in links" :key="link.os">
                <a
                    :href="link.href"
                    class="underline underline-offset-4"
                    :data-test="`script-${link.os}`"
                    >{{ link.label }}</a
                >
                <span class="text-muted-foreground"> ({{ link.version }})</span>
            </li>
        </ul>
    </section>
</template>
