<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { computed, ref, watch } from 'vue';
import { api, type Client } from '@/packages/api/src';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import ClientCombobox from '@/Components/Common/Client/ClientCombobox.vue';
import { UserCircleIcon, ArrowRightIcon } from '@heroicons/vue/24/solid';
import { Badge } from '@/packages/ui/src';
import { useMutation, useQueryClient } from '@tanstack/vue-query';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { RadioGroupIndicator, RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { Check } from '@lucide/vue';

const queryClient = useQueryClient();
const { clients } = useClientsQuery();
const { handleApiRequestNotifications, addNotification } = useNotificationsStore();

const show = defineModel('show', { default: false });
const saving = ref(false);

const props = defineProps<{
    client: Client;
}>();

const destinationClientId = ref('');
const nameClientId = ref('');

const destinationClient = computed(() => {
    return clients.value.find((client) => client.id === destinationClientId.value);
});

const nameOptions = computed(() => {
    if (destinationClient.value === undefined) {
        return [];
    }

    return [
        { id: props.client.id, name: props.client.name },
        { id: destinationClient.value.id, name: destinationClient.value.name },
    ];
});

watch(show, (isShown) => {
    if (isShown) {
        destinationClientId.value = '';
        nameClientId.value = '';
        saving.value = false;
    }
});

watch(destinationClientId, (id) => {
    if (id !== '') {
        nameClientId.value = id;
    } else {
        nameClientId.value = '';
    }
});

const mergeClient = useMutation({
    mutationFn: async ({ destinationId, nameId }: { destinationId: string; nameId: string }) => {
        const organizationId = getCurrentOrganizationId();
        if (organizationId === null) {
            throw new Error('No current organization id');
        }
        return await api.mergeClient(
            {
                client_id: destinationId,
                name_client_id: nameId,
            },
            {
                params: {
                    organization: organizationId,
                    client: props.client.id,
                },
            }
        );
    },
});

async function submit() {
    const destinationId = destinationClientId.value;
    const nameId = nameClientId.value;
    if (destinationId === '' || nameId === '') {
        addNotification('error', 'Select a client and which name to keep.');
        return;
    }
    saving.value = true;
    await handleApiRequestNotifications(
        () => mergeClient.mutateAsync({ destinationId, nameId }),
        'Clients successfully merged!',
        'There was an error merging the clients.',
        () => {
            queryClient.invalidateQueries({ queryKey: ['clients'] });
            queryClient.invalidateQueries({ queryKey: ['projects'] });
            show.value = false;
        }
    );
    saving.value = false;
}
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span> Merge Clients </span>
            </div>
        </template>

        <template #content>
            <p>
                Projects from both clients will live under the name you pick.
                <strong>This cannot be reverted!</strong>
            </p>
            <div class="py-5 flex flex-col md:flex-row gap-6 items-center">
                <div class="flex-1 w-full">
                    <Badge
                        class="flex w-full text-base text-left space-x-3 px-3 text-text-secondary font-normal cursor py-1.5">
                        <UserCircleIcon
                            class="relative z-10 w-4 text-text-secondary"></UserCircleIcon>
                        <div class="flex-1 font-medium truncate">
                            {{ client.name }}
                        </div>
                    </Badge>
                </div>
                <div>
                    <ArrowRightIcon class="relative z-10 w-4 text-muted"></ArrowRightIcon>
                </div>
                <div class="flex-1 w-full">
                    <ClientCombobox
                        v-model="destinationClientId"
                        :hidden-clients="[client]"></ClientCombobox>
                </div>
            </div>
            <RadioGroupRoot
                v-if="nameOptions.length > 0"
                :model-value="nameClientId"
                class="space-y-1"
                @update:model-value="(value) => (nameClientId = String(value ?? ''))">
                <RadioGroupItem
                    v-for="option in nameOptions"
                    :key="option.id"
                    :value="option.id"
                    class="relative flex w-full items-center rounded-md py-1.5 pl-2 pr-8 text-left text-sm font-medium text-text-secondary hover:bg-card-background-active data-[state=checked]:text-text-primary">
                    {{ option.name }}
                    <span class="absolute right-2 flex h-3.5 w-3.5 items-center justify-center">
                        <RadioGroupIndicator>
                            <Check class="h-4 w-4" />
                        </RadioGroupIndicator>
                    </span>
                </RadioGroupItem>
            </RadioGroupRoot>
        </template>
        <template #footer>
            <SecondaryButton @click="show = false"> Cancel</SecondaryButton>

            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit()">
                Merge Clients
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
