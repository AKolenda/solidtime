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
import type { TimeEntry } from '@/packages/api/src';
import TableRow from '@/Components/TableRow.vue';
import Pagination from '@/Components/Common/Pagination.vue';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import { useTimeEntriesReportQuery } from '@/utils/useTimeEntriesReportQuery';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { canViewAllTimeEntries } from '@/utils/permissions';
import {
    formatDateLocalized,
    formatHumanReadableDuration,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';

const props = defineProps<{
    projectId: string;
}>();

const pageSize = 25;
const currentPage = ref(1);

const filterParams = computed(() => ({
    project_ids: [props.projectId],
    member_id: !canViewAllTimeEntries() ? getCurrentMembershipId() : undefined,
    active: 'false' as const,
    type: 'work' as const,
    limit: pageSize,
    offset: (currentPage.value - 1) * pageSize,
}));

const { data: timeEntryResponse, isLoading } = useTimeEntriesReportQuery(filterParams);
const timeEntries = computed(() => timeEntryResponse.value?.data ?? []);
const totalEntries = computed(() => timeEntryResponse.value?.meta?.total ?? 0);

const { members } = useMembersQuery();
const { tasks } = useTasksQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const memberNames = computed(
    () => new Map(members.value.map((member) => [member.user_id, member.name]))
);
const taskNames = computed(() => new Map(tasks.value.map((task) => [task.id, task.name])));

function duration(entry: TimeEntry): string {
    const seconds =
        entry.duration ??
        (entry.end ? getLocalizedDayJs(entry.end).diff(getLocalizedDayJs(entry.start), 's') : null);
    if (seconds === null) return '--';
    return formatHumanReadableDuration(
        seconds,
        organization.value?.interval_format,
        organization.value?.number_format
    );
}

const columnSizing = useStorage<ColumnSizingState>(
    'project-time-entries-column-sizing',
    {},
    undefined,
    { eventFilter: debounceFilter(250) }
);

const columns: ColumnDef<TimeEntry>[] = [
    { id: 'date', header: 'Date', size: 130, minSize: 80 },
    { id: 'member', header: 'Member', size: 100, minSize: 80 },
    { id: 'description', header: 'Description', size: 130, minSize: 100 },
    { id: 'task', header: 'Task', size: 100, minSize: 80 },
    { id: 'duration', header: 'Duration', size: 80, minSize: 70, meta: { align: 'right' } },
];

const table = useVueTable<TimeEntry>({
    get data() {
        return timeEntries.value;
    },
    columns,
    getCoreRowModel: getCoreRowModel(),
    enableColumnResizing: true,
    columnResizeMode: 'onChange',
    state: {
        get columnSizing() {
            return columnSizing.value;
        },
    },
    onColumnSizingChange: (updater: Updater<ColumnSizingState>) => {
        columnSizing.value = typeof updater === 'function' ? updater(columnSizing.value) : updater;
    },
});

const headers = computed(() => table.getFlatHeaders());
const gridTemplate = computed(
    () =>
        `grid-template-columns: ${headers.value
            .map((header) => `${header.getSize()}px`)
            .join(' ')} minmax(0, 1fr);`
);
</script>

<template>
    <div class="flow-root max-w-[100vw] overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div
                data-testid="project_time_entries_table"
                class="grid min-w-full"
                :style="gridTemplate">
                <DetailedReportTableHeading :headers="headers"></DetailedReportTableHeading>
                <TableRow v-for="entry in timeEntries" :key="entry.id">
                    <div class="py-2.5 pr-3 pl-4 sm:pl-6 lg:pl-8 text-sm truncate tabular-nums">
                        {{ formatDateLocalized(entry.start, organization?.date_format) }}
                    </div>
                    <div class="py-2.5 px-3 text-sm truncate">
                        {{ memberNames.get(entry.user_id) ?? '' }}
                    </div>
                    <div class="py-2.5 px-3 text-sm truncate" :title="entry.description ?? ''">
                        {{ entry.description || '--' }}
                    </div>
                    <div class="py-2.5 px-3 text-sm truncate">
                        {{ entry.task_id ? (taskNames.get(entry.task_id) ?? '') : '--' }}
                    </div>
                    <div class="py-2.5 px-3 text-sm text-right tabular-nums">
                        {{ duration(entry) }}
                    </div>
                    <div></div>
                </TableRow>
                <div
                    v-if="!isLoading && timeEntries.length === 0"
                    class="col-span-full py-8 text-center text-sm text-text-secondary">
                    No time tracked on this project yet.
                </div>
            </div>
        </div>
    </div>
    <Pagination
        v-if="totalEntries > pageSize"
        v-model:page="currentPage"
        class="border-t border-row-separator"
        :total="totalEntries"
        :items-per-page="pageSize" />
</template>
