<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { MonitorUp } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { update } from '@/routes/machines/rustdesk';

const props = defineProps<{
    machineId: number;
    rustdeskId: string | null;
}>();

const form = useForm({ rustdesk_id: props.rustdeskId ?? '' });

watch(
    () => props.rustdeskId,
    (id) => form.defaults({ rustdesk_id: id ?? '' }).reset(),
);

const connectUrl = computed(() =>
    props.rustdeskId === null ? null : `rustdesk://${props.rustdeskId}`,
);

function save(): void {
    form.put(update(props.machineId).url, { preserveScroll: true });
}
</script>

<template>
    <section class="flex flex-col gap-3" data-test="remote-support-section">
        <h2 class="text-lg font-semibold">Remote support</h2>

        <div
            v-if="rustdeskId !== null"
            class="flex flex-wrap items-center gap-3"
        >
            <p class="text-sm">
                RustDesk ID
                <span class="font-mono font-medium" data-test="rustdesk-id">{{
                    rustdeskId
                }}</span>
            </p>
            <Button as-child size="sm" data-test="connect">
                <a :href="connectUrl ?? undefined">
                    <MonitorUp class="size-4" />
                    Connect
                </a>
            </Button>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            No RustDesk ID yet. It is picked up from the next scan, or enter it
            here.
        </p>

        <form class="flex max-w-md flex-col gap-2" @submit.prevent="save">
            <div class="flex items-center gap-2">
                <label for="rustdesk-id" class="sr-only">RustDesk ID</label>
                <Input
                    id="rustdesk-id"
                    v-model="form.rustdesk_id"
                    maxlength="64"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="RustDesk ID"
                    class="font-mono"
                />
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    :disabled="form.processing"
                    data-test="save-rustdesk"
                >
                    Save
                </Button>
            </div>
            <InputError :message="form.errors.rustdesk_id" />
            <p class="text-xs text-muted-foreground">
                Leave it empty and save to clear the ID.
            </p>
        </form>
    </section>
</template>
