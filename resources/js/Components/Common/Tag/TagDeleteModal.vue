<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DangerButton from '@/packages/ui/src/Buttons/DangerButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { api, type Tag } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';

const show = defineModel('show', { default: false });

const props = defineProps<{
    tag: Tag;
}>();

const emit = defineEmits<{
    confirm: [];
}>();

const enabled = computed(() => show.value && !!getCurrentOrganizationId());

// Only the total is needed, so fetch a single entry.
const { data: entriesResponse, isLoading: entriesLoading } = useQuery({
    queryKey: computed(() => ['timeEntries', 'tag-usage', props.tag.id]),
    queryFn: () =>
        api.getTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: { tag_ids: [props.tag.id], limit: 1 },
        }),
    enabled,
    staleTime: 0,
});

const { data: projectsResponse, isLoading: projectsLoading } = useQuery({
    queryKey: computed(() => ['aggregatedTimeEntries', 'tag-usage', props.tag.id]),
    queryFn: () =>
        api.getAggregatedTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: { group: 'project', tag_ids: [props.tag.id] },
        }),
    enabled,
    staleTime: 0,
});

const loading = computed(() => entriesLoading.value || projectsLoading.value);
const entryCount = computed(() => entriesResponse.value?.meta?.total ?? 0);
const projectCount = computed(
    () =>
        (projectsResponse.value?.data.grouped_data ?? []).filter((group) => group.key !== null)
            .length
);

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

function confirm() {
    show.value = false;
    emit('confirm');
}
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>Delete Tag</template>

        <template #content>
            <p v-if="loading">Checking where {{ tag.name }} is used…</p>
            <p v-else-if="entryCount > 0" data-testid="tag_delete_usage">
                <strong>{{ tag.name }}</strong> is linked to
                <strong>{{ plural(projectCount, 'project', 'projects') }}</strong> through
                {{ plural(entryCount, 'time entry', 'time entries') }}. Deleting it removes the tag
                from those entries. Are you sure?
            </p>
            <p v-else>
                Delete <strong>{{ tag.name }}</strong
                >? It is not used by any time entries.
            </p>
        </template>

        <template #footer>
            <SecondaryButton @click="show = false">Cancel</SecondaryButton>
            <DangerButton
                class="ms-3"
                :disabled="loading"
                data-testid="tag_delete_confirm"
                @click="confirm()">
                Delete
            </DangerButton>
        </template>
    </DialogModal>
</template>
