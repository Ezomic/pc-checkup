<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index, store } from '@/routes/clients';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clients', href: index() },
            { title: 'New', href: create() },
        ],
    },
});

const form = useForm({ name: '' });

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <Head title="New client" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 pt-8">
        <h1 class="text-2xl font-semibold">New client</h1>

        <form class="flex max-w-xl flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    maxlength="255"
                    autofocus
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Create client
                </Button>
                <Button as-child type="button" variant="ghost">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
