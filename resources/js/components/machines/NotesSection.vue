<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { destroy, store } from '@/routes/machines/notes';
import type { MachineNote } from '@/types';

const props = defineProps<{
    machineId: number;
    notes: MachineNote[];
}>();

const form = useForm({ body: '' });

function add(): void {
    form.post(store(props.machineId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(note: MachineNote): void {
    router.delete(destroy({ machine: props.machineId, note: note.id }).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="flex flex-col gap-4">
        <h2 class="text-lg font-semibold">Notes</h2>

        <form class="flex flex-col gap-2" @submit.prevent="add">
            <Label for="note-body" class="sr-only">New note</Label>
            <textarea
                id="note-body"
                v-model="form.body"
                rows="3"
                maxlength="5000"
                placeholder="Write a note about this machine"
                class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            />
            <InputError :message="form.errors.body" />
            <div>
                <Button type="submit" size="sm" :disabled="form.processing">
                    Add note
                </Button>
            </div>
        </form>

        <p v-if="notes.length === 0" class="text-sm text-muted-foreground">
            No notes yet.
        </p>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="note in notes"
                :key="note.id"
                class="flex items-start justify-between gap-4 rounded-xl border border-sidebar-border/70 p-3"
            >
                <div class="min-w-0">
                    <p class="text-sm break-words whitespace-pre-wrap">
                        {{ note.body }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ note.created_at.slice(0, 10) }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Delete note"
                    @click="remove(note)"
                >
                    <Trash2 class="size-4" />
                </Button>
            </li>
        </ul>
    </section>
</template>
