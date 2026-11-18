<script setup lang="ts">
import { computed, ref } from 'vue';
import { debounceFilter, useNow, useStorage } from '@vueuse/core';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { StopIcon } from '@heroicons/vue/20/solid';
import {
    getCoreRowModel,
    useVueTable,
    type ColumnDef,
    type ColumnSizingState,
    type Updater,
} from '@tanstack/vue-table';
import { api, type TimeEntry } from '@/packages/api/src';
import TableRow from '@/Components/TableRow.vue';
import Pagination from '@/packages/ui/src/Pagination.vue';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import { useTimeEntriesReportQuery } from '@/utils/useTimeEntriesReportQuery';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import {
    canUpdateOwnTimeEntries,
    canUpdateTimeEntries,
    canViewAllTimeEntries,
} from '@/utils/permissions';
import { useNotificationsStore } from '@/utils/notification';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import {
    formatDateLocalized,
    formatDuration,
    formatHumanReadableDuration,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';

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

// Running timers are pinned above the finished entries, polled so timers started elsewhere show up
const runningParams = computed(() => ({
    project_ids: [props.projectId],
    member_id: !canViewAllTimeEntries() ? getCurrentMembershipId() : undefined,
    active: 'true' as const,
    type: 'work' as const,
    limit: 100,
}));
const { data: runningResponse } = useQuery({
    queryKey: computed(() => [
        'timeEntries',
        'project-running',
        getCurrentOrganizationId(),
        runningParams.value,
    ]),
    queryFn: () =>
        api.getTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: runningParams.value,
        }),
    enabled: computed(() => !!getCurrentOrganizationId()),
    refetchInterval: 30000,
});
const runningEntries = computed(() => runningResponse.value?.data ?? []);

const now = useNow({ interval: 1000 });
function runningDuration(entry: TimeEntry): string {
    return formatDuration(
        Math.max(
            0,
            Math.floor((now.value.getTime() - getLocalizedDayJs(entry.start).valueOf()) / 1000)
        )
    );
}

// Employees can not load member names, so their own timer is "your timer"
function stopLabel(entry: TimeEntry): string {
    if (entry.member_id === getCurrentMembershipId()) {
        return 'Stop your timer';
    }
    const name = memberNames.value.get(entry.user_id);
    return name ? `Stop ${name}'s timer` : 'Stop timer';
}

function canStop(entry: TimeEntry): boolean {
    return entry.member_id === getCurrentMembershipId()
        ? canUpdateOwnTimeEntries()
        : canUpdateTimeEntries();
}

const queryClient = useQueryClient();
const { handleApiRequestNotifications } = useNotificationsStore();
const stopCandidate = ref<TimeEntry | null>(null);
const showStopDialog = ref(false);

function askToStop(entry: TimeEntry) {
    stopCandidate.value = entry;
    showStopDialog.value = true;
}

async function stopTimer() {
    const entry = stopCandidate.value;
    if (entry === null) {
        return;
    }
    // The server only stops the entry if it is still running, so a stale row can not overwrite a newer end
    await handleApiRequestNotifications(
        () =>
            api.stopTimeEntry(undefined, {
                params: {
                    organization: getCurrentOrganizationId()!,
                    timeEntry: entry.id,
                },
            }),
        'Timer stopped',
        'Failed to stop the timer'
    );
    await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['timeEntries'] }),
        queryClient.invalidateQueries({ queryKey: ['aggregatedTimeEntries'] }),
        queryClient.invalidateQueries({ queryKey: ['latestTeamActivity'] }),
        queryClient.invalidateQueries({ queryKey: ['tasks'] }),
    ]);
    if (entry.member_id === getCurrentMembershipId()) {
        await useCurrentTimeEntryStore().fetchCurrentTimeEntry();
    }
}

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
    { id: 'actions', header: '', size: 44, enableResizing: false },
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
                <TableRow
                    v-for="entry in runningEntries"
                    :key="entry.id"
                    class="[&>*]:!bg-green-500/10"
                    data-testid="project_running_time_entry">
                    <div
                        class="py-2.5 pr-3 pl-4 sm:pl-6 lg:pl-8 text-sm truncate flex items-center gap-2 font-medium text-green-700 dark:text-green-400">
                        <span class="relative flex h-2 w-2 shrink-0">
                            <span
                                class="absolute inline-flex h-full w-full rounded-full bg-green-500 opacity-60 motion-safe:animate-ping"></span>
                            <span
                                class="relative inline-flex h-2 w-2 rounded-full bg-green-500"></span>
                        </span>
                        Running
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
                    <div
                        class="py-2.5 px-3 text-sm text-right tabular-nums font-medium text-green-700 dark:text-green-400">
                        {{ runningDuration(entry) }}
                    </div>
                    <div class="flex items-center justify-center">
                        <button
                            v-if="canStop(entry)"
                            type="button"
                            class="rounded p-1.5 text-destructive hover:bg-destructive/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :title="stopLabel(entry)"
                            :aria-label="stopLabel(entry)"
                            @click="askToStop(entry)">
                            <StopIcon class="w-4 h-4" />
                        </button>
                    </div>
                    <div></div>
                </TableRow>
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
                    <div></div>
                </TableRow>
                <div
                    v-if="!isLoading && timeEntries.length === 0 && runningEntries.length === 0"
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
    <AlertDialog v-model:open="showStopDialog">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>
                    {{ stopCandidate ? stopLabel(stopCandidate) : 'Stop timer' }}?
                </AlertDialogTitle>
                <AlertDialogDescription>
                    The running time entry ends now. You can correct the times afterwards in the
                    Detailed report.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="stopTimer">
                    Stop timer
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
