<script setup lang="ts">
import { computed, ref } from 'vue';
import { debounceFilter, useStorage } from '@vueuse/core';
import {
    getCoreRowModel,
    useVueTable,
    type ColumnDef,
    type ColumnSizingState,
    type Updater,
} from '@tanstack/vue-table';
import { ClockIcon } from '@heroicons/vue/20/solid';
import type {
    Client,
    CreateClientBody,
    CreateProjectBody,
    Project,
    TimeEntry,
    UpdateMultipleTimeEntriesChangeset,
} from '@/packages/api/src';
import { Checkbox, TimeEntryEditModal } from '@/packages/ui/src';
import { useBreaksEnabled } from '@/packages/ui/src/utils/useBreaksEnabled';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useProjectsStore } from '@/utils/useProjects';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useClientsStore } from '@/utils/useClients';
import { useTagsQuery } from '@/utils/useTagsQuery';
import { useTagsStore } from '@/utils/useTags';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentOrganizationId, getCurrentUserId } from '@/utils/useUser';
import { getOrganizationCurrencyString } from '@/utils/money';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import {
    canCreateProjects,
    canUpdateTimeEntries,
    canUpdateOwnTimeEntries,
} from '@/utils/permissions';
import { collapseTimeEntries, type CollapsedTimeEntry } from '@/utils/collapseTimeEntries';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import DetailedReportTableRow from '@/Components/Common/Reporting/DetailedReportTableRow.vue';
import type { ReportEntryChanges, ReportEntryEditorContext } from './reportEntryEditing';

export type DetailedReportRow = CollapsedTimeEntry;

const props = defineProps<{
    timeEntries: TimeEntry[];
    selectedTimeEntries: TimeEntry[];
    updateTimeEntry: (entry: { id: string } & ReportEntryChanges) => Promise<unknown>;
    updateTimeEntries: (
        ids: string[],
        changes: UpdateMultipleTimeEntriesChangeset
    ) => Promise<void>;
    loadOriginalEntries?: (ids: string[]) => Promise<TimeEntry[]>;
    deleteTimeEntries: (entries: TimeEntry[]) => void | Promise<unknown>;
    duplicateTimeEntry: (entry: TimeEntry) => void;
    startTimeEntry: (entry: TimeEntry) => void;
}>();

const emit = defineEmits<{
    'update:selectedTimeEntries': [entries: TimeEntry[]];
}>();

const { projects } = useProjectsQuery();
const { tasks } = useTasksQuery();
const { clients } = useClientsQuery();
const { tags } = useTagsQuery();
const { members } = useMembersQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const breaksEnabled = useBreaksEnabled();
const currentUserId = getCurrentUserId();
const editorContext = computed<ReportEntryEditorContext>(() => ({
    projects: projects.value,
    tasks: tasks.value,
    clients: clients.value,
    tags: tags.value,
    members: members.value,
    organization: organization.value,
    userId: currentUserId,
    canChangeMember: canUpdateTimeEntries(),
    canEdit: (entry) =>
        canUpdateTimeEntries() || (canUpdateOwnTimeEntries() && entry.user_id === currentUserId),
    loadOriginalEntries: props.loadOriginalEntries,
    update: async (ids, changes) => {
        if (ids.length === 1) {
            await props.updateTimeEntry({ id: ids[0]!, ...changes });
        } else if (ids.length > 1) {
            if ('start' in changes || 'end' in changes)
                throw new Error('Choose one entry to change its time.');
            await props.updateTimeEntries(ids, changes);
        }
    },
}));

// Lookup maps so each row resolves its project / task / client / member / tags in O(1).
const projectMap = computed(() => new Map(projects.value.map((project) => [project.id, project])));
const taskMap = computed(() => new Map(tasks.value.map((task) => [task.id, task])));
const clientMap = computed(() => new Map(clients.value.map((client) => [client.id, client])));
const tagMap = computed(() => new Map(tags.value.map((tag) => [tag.id, tag])));
const memberMap = computed(() => new Map(members.value.map((member) => [member.user_id, member])));

/**
 * Column widths and the grouping toggle survive a reload. Widths are keyed by
 * column id, so adding or removing a column later just falls back to its default size.
 */
interface DetailedReportTableState {
    columnSizing: ColumnSizingState;
    collapseDuplicates: boolean;
}

const tableState = useStorage<DetailedReportTableState>(
    'detailed-report-table-state',
    {
        columnSizing: {},
        collapseDuplicates: true,
    },
    undefined,
    // `columnResizeMode: 'onChange'` fires on every mouse move, so persistence is debounced
    // while the in-memory value stays immediate.
    { mergeDefaults: true, eventFilter: debounceFilter(250) }
);

const rows = computed<DetailedReportRow[]>(() => {
    if (tableState.value.collapseDuplicates) {
        return collapseTimeEntries(props.timeEntries);
    }
    return props.timeEntries.map((entry) => ({
        ...entry,
        collapsed_count: 1,
        collapsed_ids: [entry.id],
    }));
});

// Order here defines the grid columns; DetailedReportTableRow renders its cells in the same order.
const columns: ColumnDef<DetailedReportRow>[] = [
    { id: 'select', header: '', size: 56, minSize: 56, enableResizing: false },
    { id: 'date', header: 'Date', size: 120, minSize: 80 },
    { id: 'member', header: 'Member', size: 160, minSize: 80 },
    { id: 'description', header: 'Description', size: 320, minSize: 100 },
    { id: 'project', header: 'Project', size: 280, minSize: 100 },
    { id: 'task', header: 'Task', size: 180, minSize: 80 },
    { id: 'client', header: 'Client', size: 180, minSize: 80 },
    { id: 'tags', header: 'Tags', size: 160, minSize: 80 },
    { id: 'billable', header: 'Billable', size: 90, minSize: 70 },
    { id: 'time', header: 'Time', size: 150, minSize: 100 },
    { id: 'duration', header: 'Duration', size: 120, minSize: 90 },
    { id: 'actions', header: '', size: 56, minSize: 56, enableResizing: false },
];

const table = useVueTable<DetailedReportRow>({
    get data() {
        return rows.value;
    },
    columns,
    getCoreRowModel: getCoreRowModel(),
    enableColumnResizing: true,
    columnResizeMode: 'onChange',
    state: {
        get columnSizing() {
            return tableState.value.columnSizing;
        },
    },
    onColumnSizingChange: (updater: Updater<ColumnSizingState>) => {
        tableState.value.columnSizing =
            typeof updater === 'function' ? updater(tableState.value.columnSizing) : updater;
    },
});

const headers = computed(() => table.getFlatHeaders());

// A trailing `minmax(0, 1fr)` absorbs any width left over so row backgrounds
// still reach the right edge when the columns are narrower than the viewport.
const gridTemplate = computed(
    () =>
        `grid-template-columns: ${headers.value
            .map((header) => `${header.getSize()}px`)
            .join(' ')} minmax(0, 1fr);`
);

function resetColumnWidths() {
    tableState.value.columnSizing = {};
}

const selectedIds = computed(() => new Set(props.selectedTimeEntries.map((entry) => entry.id)));

function isRowSelected(row: DetailedReportRow): boolean {
    return row.collapsed_ids.every((id) => selectedIds.value.has(id));
}

/** The original, uncollapsed entries a displayed row stands for. */
const entriesById = computed(() => new Map(props.timeEntries.map((entry) => [entry.id, entry])));
function entriesOfRow(row: DetailedReportRow): TimeEntry[] {
    return row.collapsed_ids
        .map((id) => entriesById.value.get(id))
        .filter((entry): entry is TimeEntry => !!entry);
}

function setRowSelected(row: DetailedReportRow, selected: boolean) {
    const ids = new Set(row.collapsed_ids);
    if (selected) {
        const added = props.timeEntries.filter(
            (entry) => ids.has(entry.id) && !selectedIds.value.has(entry.id)
        );
        emit('update:selectedTimeEntries', [...props.selectedTimeEntries, ...added]);
    } else {
        emit(
            'update:selectedTimeEntries',
            props.selectedTimeEntries.filter((entry) => !ids.has(entry.id))
        );
    }
}

/**
 * Actions that only make sense for a single entry (continue, duplicate) act on the
 * first entry of a grouped row.
 */
function firstEntryOfRow(row: DetailedReportRow): TimeEntry | undefined {
    return entriesOfRow(row)[0];
}

function duplicateRow(row: DetailedReportRow) {
    const entry = firstEntryOfRow(row);
    if (entry) {
        props.duplicateTimeEntry(entry);
    }
}

function startRow(row: DetailedReportRow) {
    const entry = firstEntryOfRow(row);
    if (entry) {
        props.startTimeEntry(entry);
    }
}

function canRecreate(row: DetailedReportRow): boolean {
    return row.type !== 'break' || breaksEnabled.value;
}

const showEditModal = ref(false);
const entryToEdit = ref<TimeEntry | null>(null);
const entriesToEdit = ref<TimeEntry[]>([]);
const editError = ref('');

// Let the editor choose an underlying entry rather than editing the aggregate's time range.
async function openEditModal(row: DetailedReportRow) {
    editError.value = '';
    try {
        const entries = entriesOfRow(row).filter(editorContext.value.canEdit);
        if (entries.length === 0) return;
        entriesToEdit.value = props.loadOriginalEntries
            ? await props.loadOriginalEntries(entries.map((entry) => entry.id))
            : entries;
        entryToEdit.value = entriesToEdit.value[0] ?? null;
        if (entryToEdit.value) showEditModal.value = true;
    } catch {
        editError.value = 'Could not load original times. Try opening the entry again.';
    }
}

async function handleModalUpdate(entry: TimeEntry) {
    const original = entriesToEdit.value.find((candidate) => candidate.id === entry.id);
    if (!original || !editorContext.value.canEdit(original)) {
        throw new Error('You do not have permission to edit this entry.');
    }
    await props.updateTimeEntry(entry);
    showEditModal.value = false;
}

async function handleModalDelete(timeEntryId: string) {
    const entry = props.timeEntries.find((item) => item.id === timeEntryId);
    if (entry) {
        await props.deleteTimeEntries([entry]);
    }
    showEditModal.value = false;
}

async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    return await useProjectsStore().createProject(project);
}

async function createClient(client: CreateClientBody): Promise<Client | undefined> {
    return await useClientsStore().createClient(client);
}

async function createTag(name: string) {
    return await useTagsStore().createTag(name);
}
</script>

<template>
    <div class="w-full">
        <p v-if="editError" role="alert" class="px-4 py-2 text-sm text-red-600">{{ editError }}</p>
        <div
            class="flex items-center justify-end gap-4 px-4 sm:px-6 lg:px-8 py-2 border-b border-default-background-separator">
            <label
                class="flex items-center gap-2 text-xs text-text-secondary hover:text-text-primary transition-colors cursor-pointer select-none">
                <Checkbox
                    :checked="tableState.collapseDuplicates"
                    @update:checked="tableState.collapseDuplicates = $event === true" />
                Group identical entries
            </label>
            <button
                type="button"
                class="text-xs text-text-secondary hover:text-text-primary transition-colors"
                @click="resetColumnWidths">
                Reset column widths
            </button>
        </div>
        <div class="flow-root max-w-[100vw] overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <div
                    data-testid="detailed_report_table"
                    class="grid min-w-full"
                    :style="gridTemplate">
                    <DetailedReportTableHeading :headers="headers"></DetailedReportTableHeading>
                    <template v-for="row in rows" :key="row.id">
                        <DetailedReportTableRow
                            :entry="row"
                            :selected="isRowSelected(row)"
                            :can-recreate="canRecreate(row)"
                            :projects="projectMap"
                            :tasks="taskMap"
                            :clients="clientMap"
                            :tags="tagMap"
                            :members="memberMap"
                            :organization="organization"
                            :original-entries="entriesOfRow(row)"
                            :editor-context="editorContext"
                            @selected="setRowSelected(row, true)"
                            @unselected="setRowSelected(row, false)"
                            @edit="openEditModal(row)"
                            @duplicate="duplicateRow(row)"
                            @delete="deleteTimeEntries(entriesOfRow(row))"
                            @start="startRow(row)"></DetailedReportTableRow>
                    </template>
                    <div v-if="rows.length === 0" class="col-span-full py-16 text-center">
                        <ClockIcon class="w-8 text-icon-default inline pb-2"></ClockIcon>
                        <h3 class="text-text-primary font-semibold">No time entries found</h3>
                        <p class="pb-5">Adjust the filters to see more time entries!</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <TimeEntryEditModal
        v-if="showEditModal"
        v-model:show="showEditModal"
        :time-entry="entryToEdit"
        :related-time-entries="entriesToEdit"
        :enable-estimated-time="isAllowedToPerformPremiumAction()"
        :update-time-entry="handleModalUpdate"
        :delete-time-entry="handleModalDelete"
        :create-client="createClient"
        :create-project="createProject"
        :create-tag="createTag"
        :tags="tags"
        :projects="projects"
        :tasks="tasks"
        :clients="clients"
        :currency="getOrganizationCurrencyString()"
        :organization-billable-rate="organization?.billable_rate ?? null"
        :can-create-project="canCreateProjects()" />
</template>

<style scoped></style>
