<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { create, index, store } from '@/routes/machines';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Machines', href: index() },
            { title: 'Add machine', href: create() },
        ],
    },
});

const form = useForm({
    serial: '',
    hardware_uuid: '',
    name: '',
    os: '',
    device_type: '',
    model: '',
});

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <Head title="Add machine" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <h1 class="text-xl font-semibold">Add machine</h1>
        <p class="max-w-xl text-sm text-muted-foreground">
            For a machine the checkup script cannot run on. Enter a serial
            number or a hardware UUID, at least one of the two.
        </p>

        <form class="flex max-w-xl flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="serial">Serial number</Label>
                <Input
                    id="serial"
                    v-model="form.serial"
                    maxlength="100"
                    autocomplete="off"
                />
                <InputError :message="form.errors.serial" />
            </div>

            <div class="grid gap-2">
                <Label for="hardware_uuid">Hardware UUID</Label>
                <Input
                    id="hardware_uuid"
                    v-model="form.hardware_uuid"
                    maxlength="100"
                    autocomplete="off"
                />
                <InputError :message="form.errors.hardware_uuid" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    maxlength="255"
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="os">Operating system</Label>
                <Input
                    id="os"
                    v-model="form.os"
                    maxlength="100"
                    autocomplete="off"
                />
                <InputError :message="form.errors.os" />
            </div>

            <div class="grid gap-2">
                <Label for="device_type">Device type</Label>
                <select
                    id="device_type"
                    v-model="form.device_type"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <option value="">Not set</option>
                    <option value="laptop">Laptop</option>
                    <option value="desktop">Desktop</option>
                </select>
                <InputError :message="form.errors.device_type" />
            </div>

            <div class="grid gap-2">
                <Label for="model">Model</Label>
                <Input
                    id="model"
                    v-model="form.model"
                    maxlength="255"
                    autocomplete="off"
                />
                <InputError :message="form.errors.model" />
            </div>

            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Add machine
                </Button>
                <Button as-child type="button" variant="ghost">
                    <Link :href="index()">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
