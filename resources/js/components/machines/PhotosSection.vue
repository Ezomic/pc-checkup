<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, store } from '@/routes/machines/photos';
import type { MachinePhoto } from '@/types';

const props = defineProps<{
    machineId: number;
    photos: MachinePhoto[];
}>();

const fileInput = ref<HTMLInputElement | null>(null);

const form = useForm<{ photo: File | null; caption: string }>({
    photo: null,
    caption: '',
});

function pick(event: Event): void {
    form.photo = (event.target as HTMLInputElement).files?.item(0) ?? null;
}

function upload(): void {
    form.post(store(props.machineId).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

function remove(photo: MachinePhoto): void {
    router.delete(destroy({ machine: props.machineId, photo: photo.id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Photos</h2>

        <form class="flex flex-col gap-3" @submit.prevent="upload">
            <div class="grid gap-2">
                <Label for="photo"
                    >Photo (JPEG, PNG or WebP, up to 10 MB)</Label
                >
                <input
                    id="photo"
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                    @change="pick"
                />
                <InputError :message="form.errors.photo" />
            </div>
            <div class="grid gap-2">
                <Label for="caption">Caption (optional)</Label>
                <Input id="caption" v-model="form.caption" maxlength="255" />
                <InputError :message="form.errors.caption" />
            </div>
            <div>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || form.photo === null"
                >
                    Upload photo
                </Button>
            </div>
        </form>

        <p v-if="photos.length === 0" class="text-sm text-muted-foreground">
            No photos yet.
        </p>

        <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <li
                v-for="photo in photos"
                :key="photo.id"
                class="flex flex-col gap-1 overflow-hidden rounded-xl border border-sidebar-border/70"
            >
                <a :href="photo.url" target="_blank" rel="noopener">
                    <img
                        :src="photo.url"
                        :width="photo.width"
                        :height="photo.height"
                        :alt="photo.caption ?? 'Machine photo'"
                        loading="lazy"
                        class="aspect-4/3 w-full object-cover"
                    />
                </a>
                <div class="flex items-center justify-between gap-2 px-2 pb-2">
                    <p class="truncate text-xs text-muted-foreground">
                        {{ photo.caption ?? '' }}
                    </p>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Delete photo"
                        @click="remove(photo)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
