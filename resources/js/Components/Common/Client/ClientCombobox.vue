<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { UserCircleIcon } from '@heroicons/vue/24/solid';
import { ChevronDown } from '@lucide/vue';
import type { Client } from '@/packages/api/src';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxInput,
    ComboboxItem,
    ComboboxRoot,
    ComboboxViewport,
} from 'radix-vue';
import Dropdown from '@/packages/ui/src/Input/Dropdown.vue';
import { Button } from '@/packages/ui/src/Buttons';

const { clients } = useClientsQuery();

const model = defineModel<string>({
    default: '',
});

const props = withDefaults(
    defineProps<{
        hiddenClients?: Client[];
        disabled?: boolean;
    }>(),
    {
        hiddenClients: () => [] as Client[],
        disabled: false,
    }
);

const open = ref(false);
const searchValue = ref('');
const searchInput = ref<HTMLElement | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        searchValue.value = '';
        nextTick(() => {
            // @ts-expect-error We need to access the actual HTML Element to focus
            searchInput.value?.$el?.focus();
        });
    }
});

const filteredClients = computed<Client[]>(() => {
    return clients.value.filter((client) => {
        return (
            client.name.toLowerCase().includes(searchValue.value.toLowerCase().trim() || '') &&
            !props.hiddenClients.some((hiddenClient) => hiddenClient.id === client.id)
        );
    });
});

const currentValue = computed(() => {
    if (model.value) {
        return clients.value.find((client) => client.id === model.value)?.name;
    }
    return '';
});

function selectClient(client: Client) {
    model.value = client.id;
    open.value = false;
}
</script>

<template>
    <Dropdown v-model="open" align="start" :close-on-content-click="false">
        <template #trigger>
            <Button
                :disabled="disabled"
                type="button"
                variant="input"
                class="w-full justify-between text-start font-normal">
                <div class="flex items-center gap-3 truncate">
                    <UserCircleIcon class="w-4 text-text-secondary shrink-0" />
                    <span v-if="currentValue" class="truncate text-text-primary">{{
                        currentValue
                    }}</span>
                    <span v-else class="text-muted-foreground">Select a client...</span>
                </div>
                <ChevronDown class="w-4 h-4 text-icon-default shrink-0" />
            </Button>
        </template>
        <template #content>
            <ComboboxRoot
                v-model:search-term="searchValue"
                :open="true"
                class="relative"
                :filter-function="(val: string[]) => val"
                @update:open="
                    (value: boolean) => {
                        if (!value) open = false;
                    }
                ">
                <ComboboxAnchor>
                    <ComboboxInput
                        ref="searchInput"
                        class="bg-card-background border-0 placeholder-text-tertiary text-sm text-text-primary py-2.5 focus:ring-0 border-b border-card-background-separator focus:border-card-background-separator w-full"
                        placeholder="Search for a client..." />
                </ComboboxAnchor>
                <ComboboxContent
                    :dismiss-able="false"
                    position="inline"
                    class="w-60 max-h-60 overflow-y-auto">
                    <ComboboxViewport>
                        <ComboboxItem
                            v-for="client in filteredClients"
                            :key="client.id"
                            :value="client.id"
                            class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-primary data-[highlighted]:bg-card-background-active cursor-default"
                            @select.prevent="selectClient(client)">
                            <UserCircleIcon class="w-4 text-text-secondary shrink-0" />
                            <span class="truncate">{{ client.name }}</span>
                        </ComboboxItem>
                    </ComboboxViewport>
                </ComboboxContent>
            </ComboboxRoot>
        </template>
    </Dropdown>
</template>

<style scoped></style>
