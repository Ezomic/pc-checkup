<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/machines';

const props = defineProps<{
    machineId: number;
}>();

const open = ref(false);
const processing = ref(false);

function forget(): void {
    router.delete(destroy(props.machineId).url, {
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="forget-section">
        <h2 class="text-lg font-semibold">Forget this machine</h2>
        <p class="text-sm text-muted-foreground">
            Permanently deletes this machine, all of its reports, notes,
            checklist answers and photos. Client links stop showing it.
        </p>
        <Dialog v-model:open="open">
            <DialogTrigger as-child>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    class="self-start"
                    data-test="forget-button"
                >
                    Forget this machine
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>Forget this machine?</DialogTitle>
                    <DialogDescription>
                        This deletes the machine and every report, note,
                        checklist answer and photo that belongs to it. It cannot
                        be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" data-test="forget-cancel">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        :disabled="processing"
                        data-test="forget-confirm"
                        @click="forget"
                    >
                        Delete permanently
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
