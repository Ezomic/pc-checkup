<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';
import BeforeAfterDocument from '@/components/share/BeforeAfterDocument.vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { LOCALES } from '@/lib/messages';
import { scanMoment } from '@/lib/scannedAgo';
import { beforeAfter, index, show } from '@/routes/machines';
import type { BeforeAfter, ScanOption, SharedMachine } from '@/types';

const props = defineProps<{
    machine: SharedMachine;
    client: { name: string } | null;
    comparison: BeforeAfter;
    selected: { before: number; after: number };
    scans: ScanOption[];
}>();

const { locale, t } = useLocale();

const before = ref(props.selected.before);
const after = ref(props.selected.after);

const title = computed(
    () =>
        props.machine.name ?? props.machine.model ?? t('share.unnamedMachine'),
);

const options = computed(() =>
    props.scans.map((scan) => ({
        id: scan.id,
        label:
            scan.scanned_at === null
                ? `#${scan.id}`
                : scanMoment(scan.scanned_at, locale.value),
    })),
);

function url(first: number, second: number, language: string): string {
    return beforeAfter.url(props.machine.id, {
        query: { before: first, after: second, lang: language },
    });
}

function compare(): void {
    router.get(url(before.value, after.value, locale.value));
}

function print(): void {
    window.print();
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Machines', href: index() }],
    },
});
</script>

<template>
    <Head :title="`${t('ba.title')} - ${title}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 pt-8">
        <div class="flex flex-col gap-4 print:hidden" data-test="controls">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link
                    :href="show(machine.id)"
                    class="flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline"
                    data-test="back"
                >
                    <ArrowLeft class="size-4" />
                    {{ title }}
                </Link>
                <div class="flex flex-wrap items-center gap-2">
                    <nav aria-label="Language" class="flex gap-1 text-sm">
                        <a
                            v-for="option in LOCALES"
                            :key="option"
                            :href="url(selected.before, selected.after, option)"
                            :aria-current="
                                option === locale ? 'true' : undefined
                            "
                            class="rounded-md px-2 py-1"
                            :class="
                                option === locale
                                    ? 'bg-muted font-medium'
                                    : 'text-muted-foreground underline-offset-4 hover:underline'
                            "
                            :data-test="`lang-${option}`"
                        >
                            {{ option === 'nl' ? 'Nederlands' : 'English' }}
                        </a>
                    </nav>
                    <Button
                        type="button"
                        size="sm"
                        data-test="print"
                        @click="print"
                    >
                        <Printer class="size-4" />
                        Print / save as PDF
                    </Button>
                </div>
            </div>

            <form
                class="flex flex-wrap items-end gap-3 rounded-xl border border-border p-3 text-sm"
                data-test="picker"
                @submit.prevent="compare"
            >
                <label class="flex flex-col gap-1">
                    <span class="text-muted-foreground">Before</span>
                    <select
                        v-model="before"
                        class="rounded-md border border-input bg-background px-2 py-1.5"
                        data-test="pick-before"
                    >
                        <option
                            v-for="scan in options"
                            :key="scan.id"
                            :value="scan.id"
                        >
                            {{ scan.label }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-muted-foreground">After</span>
                    <select
                        v-model="after"
                        class="rounded-md border border-input bg-background px-2 py-1.5"
                        data-test="pick-after"
                    >
                        <option
                            v-for="scan in options"
                            :key="scan.id"
                            :value="scan.id"
                        >
                            {{ scan.label }}
                        </option>
                    </select>
                </label>
                <Button type="submit" size="sm" variant="outline">
                    Compare
                </Button>
            </form>
        </div>

        <BeforeAfterDocument
            :machine="machine"
            :client-name="client?.name ?? null"
            :comparison="comparison"
        />
    </div>
</template>
