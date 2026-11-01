<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { LayoutGrid } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppIcon from '@/components/AppIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { PortalApp } from '@/types';

const page = usePage();

const open = ref(false);

const apps = computed<PortalApp[]>(() => page.props.portalApps ?? []);
</script>

<template>
    <Dialog v-model:open="open">
        <TooltipProvider :delay-duration="0">
            <Tooltip>
                <TooltipTrigger as-child>
                    <DialogTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-8"
                            aria-label="Switch app"
                        >
                            <LayoutGrid class="size-4" />
                        </Button>
                    </DialogTrigger>
                </TooltipTrigger>
                <TooltipContent>
                    <p>Switch app</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Your apps</DialogTitle>
                <DialogDescription>
                    Jump to another Thijssensoftware app you have access to.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="apps.length === 0"
                class="py-8 text-center text-sm text-muted-foreground"
            >
                No other apps available.
            </p>

            <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <component
                    :is="app.current ? 'div' : 'a'"
                    v-for="app in apps"
                    :key="app.slug"
                    :href="app.current ? undefined : app.launch_url"
                    :aria-current="app.current ? 'page' : undefined"
                    class="flex flex-col items-center gap-2 rounded-lg border border-border p-4 text-center transition-colors"
                    :class="
                        app.current
                            ? 'cursor-default bg-muted/50'
                            : 'hover:border-primary/40 hover:bg-accent/60'
                    "
                >
                    <AppIcon
                        :launch-url="app.launch_url"
                        :initials="app.initials"
                        :accent="app.accent"
                    />
                    <span class="text-sm font-medium">{{ app.name }}</span>
                    <span
                        v-if="app.current"
                        class="text-[11px] text-muted-foreground"
                    >
                        Current
                    </span>
                </component>
            </div>
        </DialogContent>
    </Dialog>
</template>
