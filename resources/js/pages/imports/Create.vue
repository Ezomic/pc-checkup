<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckupDownloads from '@/components/CheckupDownloads.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, store } from '@/routes/imports';
import { index } from '@/routes/machines';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Machines', href: index() },
            { title: 'Import report', href: create() },
        ],
    },
});

const form = useForm<{ file: File | null; text: string }>({
    file: null,
    text: '',
});

const reportError = computed(
    () => (form.errors as Record<string, string | undefined>).report,
);

function pick(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.file = input.files?.item(0) ?? null;
}

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <Head title="Import report" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Import report</h1>

        <div class="flex max-w-xl flex-col gap-2 rounded-lg border p-4">
            <p class="text-sm font-medium">Need a report first?</p>
            <CheckupDownloads />
        </div>

        <form class="flex max-w-xl flex-col gap-6" @submit.prevent="submit">
            <InputError :message="reportError" />

            <div class="grid gap-2">
                <Label for="file">Report file (.md or .txt)</Label>
                <input
                    id="file"
                    type="file"
                    accept=".md,.txt,text/markdown,text/plain"
                    class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                    @change="pick"
                />
                <InputError :message="form.errors.file" />
            </div>

            <div class="grid gap-2">
                <Label for="text">Or paste the report</Label>
                <textarea
                    id="text"
                    v-model="form.text"
                    rows="10"
                    class="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                />
                <InputError :message="form.errors.text" />
            </div>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Import
                </Button>
                <Button as-child type="button" variant="ghost">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
