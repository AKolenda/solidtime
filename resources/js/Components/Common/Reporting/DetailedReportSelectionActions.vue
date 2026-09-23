<script setup lang="ts">
import { ref } from 'vue';
import { PencilSquareIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import type {
    Client,
    CreateClientBody,
    CreateProjectBody,
    Project,
    Tag,
    Task,
    TimeEntry,
    UpdateMultipleTimeEntriesChangeset,
} from '@/packages/api/src';
import { Button } from '@/packages/ui/src';
import TimeEntryMassUpdateModal from '@/packages/ui/src/TimeEntry/TimeEntryMassUpdateModal.vue';

// The detailed report's version of TimeEntryMassActionRow: select-all lives in the table
// header, so this only carries the actions and fits inside the filter row.
defineProps<{
    selectedTimeEntries: TimeEntry[];
    deleteSelected: () => void;
    projects: Project[];
    tasks: Task[];
    tags: Tag[];
    clients: Client[];
    createTag: (name: string) => Promise<Tag | undefined>;
    createProject: (project: CreateProjectBody) => Promise<Project | undefined>;
    createClient: (client: CreateClientBody) => Promise<Client | undefined>;
    updateTimeEntries: (changeset: UpdateMultipleTimeEntriesChangeset) => Promise<void>;
    currency: string;
    enableEstimatedTime: boolean;
    canCreateProject: boolean;
    organizationBillableRate: number | null;
}>();

const emit = defineEmits<{
    submit: [];
    clear: [];
}>();

const showMassUpdateModal = ref(false);
</script>

<template>
    <TimeEntryMassUpdateModal
        v-model:show="showMassUpdateModal"
        :projects
        :tasks
        :tags
        :clients
        :create-tag
        :create-project
        :create-client
        :update-time-entries
        :enable-estimated-time
        :can-create-project
        :currency
        :organization-billable-rate="organizationBillableRate"
        :time-entries="selectedTimeEntries"
        @submit="emit('submit')"></TimeEntryMassUpdateModal>
    <div
        v-if="selectedTimeEntries.length"
        class="flex items-center gap-1 text-sm font-medium"
        data-testid="detailed_report_selection_actions">
        <span class="whitespace-nowrap pr-1 text-text-secondary tabular-nums">
            {{ selectedTimeEntries.length }} selected
        </span>
        <Button
            variant="ghost"
            size="xs"
            class="text-text-tertiary hover:text-text-secondary"
            aria-label="Edit selected"
            title="Edit selected"
            @click="showMassUpdateModal = true">
            <PencilSquareIcon class="w-4"></PencilSquareIcon>
        </Button>
        <Button
            variant="ghost"
            size="xs"
            class="text-red-400 hover:text-red-500 hover:bg-red-500/10"
            aria-label="Delete selected"
            title="Delete selected"
            @click="deleteSelected">
            <TrashIcon class="w-3.5"></TrashIcon>
        </Button>
        <Button
            variant="ghost"
            size="xs"
            class="text-text-tertiary hover:text-text-secondary"
            aria-label="Clear selection"
            title="Clear selection"
            @click="emit('clear')">
            <XMarkIcon class="w-4"></XMarkIcon>
        </Button>
    </div>
</template>
