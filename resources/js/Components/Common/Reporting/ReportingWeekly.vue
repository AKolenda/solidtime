<script setup lang="ts">
import { computed, type ComputedRef, inject, ref } from 'vue';
import type { Dayjs } from 'dayjs';
import { useStorage } from '@vueuse/core';
import {
    getCoreRowModel,
    useVueTable,
    type ColumnDef,
    type ColumnSizingState,
    type Updater,
} from '@tanstack/vue-table';
import { ArrowDownTrayIcon, ChartBarIcon, UserGroupIcon } from '@heroicons/vue/20/solid';
import { FolderIcon } from '@heroicons/vue/16/solid';
import { ChevronRight } from '@lucide/vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import ReportingTabNavbar from '@/Components/Common/Reporting/ReportingTabNavbar.vue';
import ReportingFilterBadge from '@/Components/Common/Reporting/ReportingFilterBadge.vue';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import ReportingWeeklyJobRows from '@/Components/Common/Reporting/ReportingWeeklyJobRows.vue';
import MemberMultiselectDropdown from '@/Components/Common/Member/MemberMultiselectDropdown.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import DateRangeNavigator from '@/packages/ui/src/Input/DateRangeNavigator.vue';
import LoadingSpinner from '@/packages/ui/src/LoadingSpinner.vue';
import { SecondaryButton } from '@/packages/ui/src';
import {
    formatHumanReadableDuration,
    getDayJsInstance,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import {
    type AggregatedTimeEntries,
    type AggregatedTimeEntriesQueryParams,
    type Organization,
} from '@/packages/api/src';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useReportingStore } from '@/utils/useReporting';
import { getCurrentMembershipId, getCurrentRole, getCurrentUser } from '@/utils/useUser';

type View = 'days' | 'weeks';
type Column = { id: string; label: string; days: string[]; weekStart?: Dayjs };
type Row = {
    userId: string;
    memberId: string | null;
    name: string;
    cells: number[];
    total: number;
};

// Weeks view spans this many weeks, ending with the selected one.
const WEEKS_SHOWN = 8;
// A week cell is fully shaded at this many hours.
const FULL_WEEK_SECONDS = 45 * 3600;

const organization = inject<ComputedRef<Organization>>('organization');
const isEmployee = getCurrentRole() === 'employee';

const view = useStorage<View>('reporting-weekly-view', 'days');
const weekdaysOnly = useStorage<boolean>('reporting-weekly-weekdays-only', true);
const jobsOnly = useStorage<boolean>('reporting-weekly-jobs-only', true);
const columnSizing = useStorage<ColumnSizingState>('reporting-weekly-column-sizing', {});
const selectedMembers = ref<string[]>([]);
const expanded = ref<Set<string>>(new Set());

function lastWeekStart(): Dayjs {
    // getDayJsInstance applies the user's first day of the week before startOf('week').
    getDayJsInstance();
    return getLocalizedDayJs().startOf('week').subtract(1, 'week');
}
const weekStart = ref<Dayjs>(lastWeekStart());

const rangeStart = computed(() =>
    view.value === 'weeks' ? weekStart.value.subtract(WEEKS_SHOWN - 1, 'week') : weekStart.value
);
const rangeDayCount = computed(() => (view.value === 'weeks' ? WEEKS_SHOWN * 7 : 7));

function isShownDay(day: Dayjs): boolean {
    return !weekdaysOnly.value || (day.day() >= 1 && day.day() <= 5);
}

function daysOfWeek(start: Dayjs): string[] {
    const days: string[] = [];
    for (let i = 0; i < 7; i++) {
        const day = start.add(i, 'day');
        if (isShownDay(day)) {
            days.push(day.format('YYYY-MM-DD'));
        }
    }
    return days;
}

const columns = computed<Column[]>(() => {
    if (view.value === 'days') {
        return daysOfWeek(weekStart.value).map((day, index) => ({
            id: `d${index}`,
            label: getDayJsInstance()(day).format('ddd D'),
            days: [day],
        }));
    }
    return Array.from({ length: WEEKS_SHOWN }, (_, index) => {
        const start = rangeStart.value.add(index, 'week');
        return {
            id: `w${index}`,
            label: start.format('MMM D'),
            days: daysOfWeek(start),
            weekStart: start,
        };
    });
});

const baseParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    start: rangeStart.value.startOf('day').utc().format(),
    end: rangeStart.value.add(rangeDayCount.value, 'day').startOf('day').utc().format(),
    group: 'user',
    sub_group: 'day',
    type: 'work',
    member_ids: selectedMembers.value.length > 0 ? selectedMembers.value : undefined,
    member_id: isEmployee ? getCurrentMembershipId() : undefined,
}));

// The aggregate API can only include "no project" time, not leave it out, so jobs-only
// subtracts a second query that counts just the time without a project.
const noProjectParams = computed<AggregatedTimeEntriesQueryParams>(() => ({
    ...baseParams.value,
    project_ids: ['none'],
}));

// No previous-key placeholders: rows from one filter must never mix with another's.
const {
    data: allResponse,
    isPending: allPending,
    isError: allError,
} = useAggregatedTimeEntriesQuery('weekly', baseParams, { keepPreviousData: false });
const {
    data: noProjectResponse,
    isPending: noProjectPending,
    isError: noProjectError,
} = useAggregatedTimeEntriesQuery('weekly-no-project', noProjectParams, {
    enabled: () => jobsOnly.value,
    keepPreviousData: false,
});

const isLoading = computed(() => allPending.value || (jobsOnly.value && noProjectPending.value));
const isError = computed(() => allError.value || (jobsOnly.value && noProjectError.value));

function secondsByUserAndDay(data: AggregatedTimeEntries | undefined) {
    const result = new Map<string, Map<string, number>>();
    for (const user of data?.grouped_data ?? []) {
        if (user.key === null) continue;
        const days = new Map<string, number>();
        for (const day of user.grouped_data ?? []) {
            if (day.key !== null) days.set(day.key, day.seconds);
        }
        result.set(user.key, days);
    }
    return result;
}

const { getNameForReportingRowEntry } = useReportingStore();
const { members } = useMembersQuery({ enabled: () => !isEmployee });

// Everyone in the report gets a row, so people with no hours show up as zeros.
const rosterUserIds = computed<string[]>(() => {
    if (isEmployee) return [getCurrentUser().id];
    return members.value
        .filter((member) =>
            selectedMembers.value.length > 0
                ? selectedMembers.value.includes(member.id)
                : !member.is_placeholder
        )
        .map((member) => member.user_id);
});

const rows = computed<Row[]>(() => {
    if (isLoading.value || isError.value) return [];
    const all = secondsByUserAndDay(allResponse.value?.data as AggregatedTimeEntries | undefined);
    const noProject = jobsOnly.value
        ? secondsByUserAndDay(noProjectResponse.value?.data as AggregatedTimeEntries | undefined)
        : new Map<string, Map<string, number>>();

    const userIds = new Set([...rosterUserIds.value, ...all.keys()]);
    return [...userIds]
        .map((userId) => {
            const days = all.get(userId) ?? new Map<string, number>();
            const cells = columns.value.map((column) =>
                column.days.reduce(
                    (sum, day) =>
                        sum + (days.get(day) ?? 0) - (noProject.get(userId)?.get(day) ?? 0),
                    0
                )
            );
            return {
                userId,
                memberId: isEmployee
                    ? (getCurrentMembershipId() ?? null)
                    : (members.value.find((member) => member.user_id === userId)?.id ?? null),
                name: getNameForReportingRowEntry(userId, 'user') ?? 'Unknown member',
                cells,
                total: cells.reduce((sum, seconds) => sum + seconds, 0),
            };
        })
        .sort((a, b) => a.name.localeCompare(b.name));
});

const columnTotals = computed(() =>
    columns.value.map((_, index) => rows.value.reduce((sum, row) => sum + row.cells[index]!, 0))
);
const grandTotal = computed(() => columnTotals.value.reduce((sum, seconds) => sum + seconds, 0));

function rowSummary(row: Row): number {
    return view.value === 'weeks' ? row.total / WEEKS_SHOWN : row.total;
}

// ── Table (column sizes and resize grips) ───────────────────────────
const tableColumns = computed<ColumnDef<Row>[]>(() => [
    { id: 'name', header: 'Employee', size: 200, minSize: 140 },
    ...columns.value.map((column) => ({
        id: column.id,
        header: column.label,
        size: view.value === 'weeks' ? 106 : 110,
        minSize: 80,
        meta: { align: 'right' },
    })),
    {
        id: 'total',
        header: view.value === 'weeks' ? 'Avg / week' : 'Week',
        size: 120,
        minSize: 90,
        meta: { align: 'right' },
    },
]);

const table = useVueTable<Row>({
    get data() {
        return rows.value;
    },
    get columns() {
        return tableColumns.value;
    },
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

const headers = computed(() => {
    // Re-read when the column set changes so the headers follow the view.
    void tableColumns.value;
    return table.getFlatHeaders();
});

const gridTemplate = computed(
    () =>
        `grid-template-columns: ${headers.value
            .map((header) => `${header.getSize()}px`)
            .join(' ')} minmax(0, 1fr);`
);

// ── Formatting ──────────────────────────────────────────────────────
function formatSeconds(seconds: number): string {
    if (seconds <= 0) return '–';
    return formatHumanReadableDuration(
        seconds,
        organization?.value?.interval_format,
        organization?.value?.number_format
    );
}

function weekShade(seconds: number): string {
    const strength = Math.min(1, seconds / FULL_WEEK_SECONDS) * 0.22;
    return `background-color: rgba(var(--color-accent-400), ${strength.toFixed(3)})`;
}

const rangeLabel = computed(() => {
    const first = columns.value[0]?.days[0];
    const last = columns.value.at(-1)?.days.at(-1);
    const start = first ? getDayJsInstance()(first) : rangeStart.value;
    const end = last ? getDayJsInstance()(last) : weekStart.value.add(6, 'day');
    const year = end.year() !== getLocalizedDayJs().year() ? `, ${end.year()}` : '';
    if (start.isSame(end, 'month')) {
        return `${start.format('MMM D')} – ${end.format('D')}${year}`;
    }
    return `${start.format('MMM D')} – ${end.format('MMM D')}${year}`;
});

const secondaryLabel = computed(() => {
    if (view.value === 'weeks') return `${WEEKS_SHOWN} weeks`;
    const current = lastWeekStart().add(1, 'week');
    if (weekStart.value.isSame(current, 'day')) return 'This week';
    if (weekStart.value.isSame(lastWeekStart(), 'day')) return 'Last week';
    return `W${weekStart.value.week()}`;
});

// ── Actions ─────────────────────────────────────────────────────────
function step(direction: 1 | -1) {
    weekStart.value = weekStart.value.add(
        direction * (view.value === 'weeks' ? WEEKS_SHOWN : 1),
        'week'
    );
}

function openWeek(column: Column) {
    if (!column.weekStart) return;
    weekStart.value = column.weekStart;
    view.value = 'days';
}

function toggleRow(row: Row) {
    const next = new Set(expanded.value);
    if (next.has(row.userId)) {
        next.delete(row.userId);
    } else {
        next.add(row.userId);
    }
    expanded.value = next;
}

function csvCell(value: string): string {
    // Keep spreadsheets from running a name like "=SUM(...)" as a formula.
    if (/^[=+\-@\t\r]/.test(value)) value = `'${value}`;
    return /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}

function hours(seconds: number): string {
    return (Math.max(0, seconds) / 3600).toFixed(2);
}

function exportCsv() {
    const header = [
        'Employee',
        ...columns.value.map((column) =>
            column.weekStart ? `Week of ${column.weekStart.format('YYYY-MM-DD')}` : column.days[0]!
        ),
        view.value === 'weeks' ? 'Average per week' : 'Total',
    ];
    const lines = [header.map(csvCell).join(',')];
    for (const row of rows.value) {
        lines.push(
            [row.name, ...row.cells.map(hours), hours(rowSummary(row))].map(csvCell).join(',')
        );
    }
    lines.push(
        [
            'Total',
            ...columnTotals.value.map(hours),
            hours(view.value === 'weeks' ? grandTotal.value / WEEKS_SHOWN : grandTotal.value),
        ].join(',')
    );
    const blob = new Blob([lines.join('\n') + '\n'], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `weekly-hours-${view.value}-${weekStart.value.format('YYYY-MM-DD')}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}

const segmentClass =
    'h-8 px-3 text-sm font-medium transition text-text-tertiary hover:text-text-primary hover:bg-card-background-active aria-pressed:bg-card-background-active aria-pressed:text-text-primary';
</script>

<template>
    <MainContainer
        class="h-14 sm:h-16 border-b border-default-background-separator flex justify-between items-center">
        <div class="flex items-center space-x-3 sm:space-x-6">
            <PageTitle :icon="ChartBarIcon" title="Reporting"></PageTitle>
            <ReportingTabNavbar active="weekly" class="hidden sm:flex"></ReportingTabNavbar>
        </div>
        <SecondaryButton
            :icon="ArrowDownTrayIcon"
            :disabled="rows.length === 0"
            data-testid="weekly_export"
            @click="exportCsv">
            Export CSV
        </SecondaryButton>
    </MainContainer>
    <MainContainer class="sm:hidden py-2 border-b border-default-background-separator">
        <ReportingTabNavbar active="weekly"></ReportingTabNavbar>
    </MainContainer>
    <div class="py-2.5 w-full border-b border-default-background-separator">
        <MainContainer class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <div
                    class="inline-flex overflow-hidden rounded-md border border-input bg-card-background shadow-xs dark:bg-transparent divide-x divide-border-secondary"
                    role="group"
                    aria-label="View">
                    <button
                        type="button"
                        :class="segmentClass"
                        :aria-pressed="view === 'days'"
                        data-testid="weekly_view_days"
                        @click="view = 'days'">
                        Days
                    </button>
                    <button
                        type="button"
                        :class="segmentClass"
                        :aria-pressed="view === 'weeks'"
                        data-testid="weekly_view_weeks"
                        @click="view = 'weeks'">
                        Weeks
                    </button>
                </div>
                <MemberMultiselectDropdown v-if="!isEmployee" v-model="selectedMembers">
                    <template #trigger>
                        <ReportingFilterBadge
                            :count="selectedMembers.length"
                            :active="selectedMembers.length > 0"
                            title="Members"
                            :icon="UserGroupIcon" />
                    </template>
                </MemberMultiselectDropdown>
                <ReportingFilterBadge
                    title="Jobs only"
                    :icon="FolderIcon"
                    :active="jobsOnly"
                    :aria-pressed="jobsOnly"
                    data-testid="weekly_jobs_only"
                    @click="jobsOnly = !jobsOnly" />
                <div
                    class="inline-flex overflow-hidden rounded-md border border-input bg-card-background shadow-xs dark:bg-transparent divide-x divide-border-secondary"
                    role="group"
                    aria-label="Days shown">
                    <button
                        type="button"
                        :class="segmentClass"
                        :aria-pressed="weekdaysOnly"
                        data-testid="weekly_weekdays_only"
                        @click="weekdaysOnly = true">
                        Mon–Fri
                    </button>
                    <button
                        type="button"
                        :class="segmentClass"
                        :aria-pressed="!weekdaysOnly"
                        data-testid="weekly_full_week"
                        @click="weekdaysOnly = false">
                        Full week
                    </button>
                </div>
            </div>
            <DateRangeNavigator
                class="ml-auto"
                :label="rangeLabel"
                :secondary-label="secondaryLabel"
                previous-test-id="weekly_previous"
                trigger-test-id="weekly_range"
                next-test-id="weekly_next"
                trigger-aria-label="Go to last week"
                @previous="step(-1)"
                @next="step(1)"
                @select="weekStart = lastWeekStart()" />
        </MainContainer>
    </div>
    <div class="w-full">
        <div class="flow-root max-w-[100vw] overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <div
                    data-testid="weekly_table"
                    class="grid min-w-full text-sm tabular-nums"
                    :style="gridTemplate">
                    <DetailedReportTableHeading
                        :headers="headers"
                        class="[&>*:first-child]:sticky [&>*:first-child]:left-0 [&>*:first-child]:z-[1]">
                        <template v-for="column in columns" :key="column.id" #[column.id]>
                            <button
                                v-if="column.weekStart"
                                type="button"
                                class="truncate hover:text-text-primary hover:underline"
                                :title="`Open the week of ${column.label}`"
                                @click="openWeek(column)">
                                {{ column.label }}
                            </button>
                            <span v-else class="truncate">{{ column.label }}</span>
                        </template>
                    </DetailedReportTableHeading>
                    <template v-for="row in rows" :key="row.userId">
                        <div
                            class="contents text-text-primary [&>*]:border-b [&>*]:border-row-separator [&>*]:h-[44px] [&>*]:flex [&>*]:items-center [&>*]:px-3 [&>*]:whitespace-nowrap"
                            :class="
                                view === 'days'
                                    ? 'cursor-pointer [&:hover>*]:bg-card-background-active'
                                    : ''
                            "
                            data-testid="weekly_row"
                            @click="view === 'days' && toggleRow(row)">
                            <div
                                class="pl-4 sm:pl-6 lg:pl-8 min-w-0 sticky left-0 z-[1] bg-default-background">
                                <button
                                    v-if="view === 'days'"
                                    type="button"
                                    class="flex items-center gap-1.5 min-w-0 rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    :aria-expanded="expanded.has(row.userId)"
                                    @click.stop="toggleRow(row)">
                                    <ChevronRight
                                        class="w-4 h-4 shrink-0 text-icon-default transition-transform"
                                        :class="expanded.has(row.userId) ? 'rotate-90' : ''" />
                                    <span class="truncate">{{ row.name }}</span>
                                </button>
                                <span v-else class="truncate">{{ row.name }}</span>
                            </div>
                            <div
                                v-for="(seconds, index) in row.cells"
                                :key="columns[index]?.id"
                                class="justify-end"
                                :class="seconds <= 0 ? 'text-text-quaternary' : ''"
                                :style="view === 'weeks' ? weekShade(seconds) : undefined">
                                {{ formatSeconds(seconds) }}
                            </div>
                            <div class="justify-end font-semibold">
                                {{ formatSeconds(rowSummary(row)) }}
                            </div>
                            <div></div>
                        </div>
                        <ReportingWeeklyJobRows
                            v-if="view === 'days' && expanded.has(row.userId)"
                            :member-id="row.memberId"
                            :columns="columns"
                            :start="baseParams.start"
                            :end="baseParams.end"
                            :jobs-only="jobsOnly"
                            :format-seconds="formatSeconds" />
                    </template>
                    <div
                        v-if="rows.length > 0"
                        class="contents font-semibold text-text-secondary [&>*]:h-[44px] [&>*]:flex [&>*]:items-center [&>*]:px-3 [&>*]:whitespace-nowrap [&>*]:bg-row-heading-background">
                        <div class="pl-4 sm:pl-6 lg:pl-8 sticky left-0 z-[1]">Total</div>
                        <div
                            v-for="(seconds, index) in columnTotals"
                            :key="columns[index]?.id"
                            class="justify-end">
                            {{ formatSeconds(seconds) }}
                        </div>
                        <div class="justify-end text-text-primary">
                            {{
                                formatSeconds(
                                    view === 'weeks' ? grandTotal / WEEKS_SHOWN : grandTotal
                                )
                            }}
                        </div>
                        <div></div>
                    </div>
                    <div
                        v-else-if="isError"
                        class="col-span-full py-16 text-center text-text-tertiary">
                        Couldn't load hours. Try again.
                    </div>
                    <div v-else-if="isLoading" class="col-span-full flex justify-center py-16">
                        <LoadingSpinner />
                    </div>
                    <div v-else class="col-span-full py-16 text-center text-text-tertiary">
                        No time entries in this range
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
