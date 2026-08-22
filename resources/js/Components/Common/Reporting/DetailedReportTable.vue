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
import { getCurrentOrganizationId } from '@/utils/useUser';
import { getOrganizationCurrencyString } from '@/utils/money';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { canCreateProjects } from '@/utils/permissions';
import { collapseTimeEntries, type CollapsedTimeEntry } from '@/utils/collapseTimeEntries';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import DetailedReportTableRow from '@/Components/Common/Reporting/DetailedReportTableRow.vue';

export type DetailedReportRow = CollapsedTimeEntry;

const props = defineProps<{
    timeEntries: TimeEntry[];
    selectedTimeEntries: TimeEntry[];
    updateTimeEntry: (entry: TimeEntry) => Promise<unknown>;
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
function entriesOfRow(row: DetailedReportRow): TimeEntry[] {
    const ids = new Set(row.collapsed_ids);
    return props.timeEntries.filter((entry) => ids.has(entry.id));
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
 * Actions that only make sense for a single entry (continue, duplicate, edit) act on the
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

// A grouped row edits its first underlying entry — the other members of the group
// keep their own start/end times, which a single edit could not represent.
function openEditModal(row: DetailedReportRow) {
    entryToEdit.value = firstEntryOfRow(row) ?? null;
    if (entryToEdit.value) {
        showEditModal.value = true;
    }
}

async function handleModalUpdate(entry: TimeEntry) {
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
