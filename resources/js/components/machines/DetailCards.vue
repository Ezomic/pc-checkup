<script setup lang="ts">
import { computed, ref } from 'vue';
import DetailCardItem from '@/components/machines/DetailCardItem.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { firstTab, groupCards, twoColumns } from '@/lib/detailCards';
import type { DetailCard } from '@/types';

const props = defineProps<{
    cards: DetailCard[];
}>();

const groups = computed(() => groupCards(props.cards));
const tab = ref(firstTab(groups.value));

function needAttention(count: number): string {
    return count === 1
        ? ', 1 card needs attention'
        : `, ${count} cards need attention`;
}
</script>

<template>
    <section
        v-if="groups.length > 0"
        class="@container"
        aria-label="Report details"
        data-test="detail-cards"
    >
        <Tabs v-model="tab" class="gap-4">
            <TabsList
                class="h-auto w-full flex-wrap justify-start sm:w-fit print:hidden"
                aria-label="Report sections"
            >
                <TabsTrigger
                    v-for="group in groups"
                    :key="group.title"
                    :value="group.title"
                    class="flex-none"
                    data-test="detail-tab"
                >
                    {{ group.title }}
                    <span
                        v-if="group.attention > 0"
                        class="rounded-full bg-red-600 px-1.5 text-xs leading-5 font-semibold text-white"
                        data-test="detail-tab-count"
                    >
                        <span aria-hidden="true">{{ group.attention }}</span>
                        <span class="sr-only">{{
                            needAttention(group.attention)
                        }}</span>
                    </span>
                </TabsTrigger>
            </TabsList>

            <TabsContent
                v-for="group in groups"
                :key="group.title"
                :value="group.title"
                force-mount
                class="data-[state=inactive]:hidden print:mb-6 print:data-[state=inactive]:block"
                data-test="detail-group"
            >
                <h2 class="mb-3 hidden text-lg font-semibold print:block">
                    {{ group.title }}
                </h2>
                <div
                    class="flex flex-col gap-4 @2xl:grid @2xl:grid-cols-2 @2xl:items-start"
                >
                    <div
                        v-for="(column, index) in twoColumns(group.cards)"
                        :key="index"
                        class="contents @2xl:flex @2xl:flex-col @2xl:gap-4"
                        data-test="detail-column"
                    >
                        <DetailCardItem
                            v-for="item in column"
                            :key="item.card.id"
                            :card="item.card"
                            :style="{ order: item.order }"
                        />
                    </div>
                </div>
            </TabsContent>
        </Tabs>
    </section>
</template>
