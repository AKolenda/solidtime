<script setup lang="ts">
import { computed } from 'vue';
import { debounceFilter, useStorage } from '@vueuse/core';
import {
    getCoreRowModel,
    useVueTable,
    type ColumnDef,
    type ColumnSizingState,
    type Updater,
} from '@tanstack/vue-table';
import TableRow from '@/Components/TableRow.vue';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import { useQuery } from '@tanstack/vue-query';
import { api } from '@/packages/api/src';
import { useMembersQuery } from '@/utils/useMembersQuery';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { canViewAllTimeEntries } from '@/utils/permissions';
import { formatHumanReadableDuration } from '@/packages/ui/src/utils/time';

const props = defineProps<{
    projectId: string;
}>();

interface MemberTimeRow {
    userId: string;
    memberId: string | null;
    name: string;
    seconds: number;
}

const filterParams = computed(() => ({
    group: 'user' as const,
    project_ids: [props.projectId],
    member_id: !canViewAllTimeEntries() ? getCurrentMembershipId() : undefined,
    type: 'work' as const,
}));

// All-time totals, so this skips useAggregatedTimeEntriesQuery and its required date range.
const { data: aggregate, isLoading } = useQuery({
    queryKey: computed(() => [
        'aggregatedTimeEntries',
        'project-member-time',
        getCurrentOrganizationId(),
        filterParams.value,
    ]),
    queryFn: () =>
        api.getAggregatedTimeEntries({
            params: { organization: getCurrentOrganizationId()! },
            queries: filterParams.value,
        }),
    enabled: computed(() => !!getCurrentOrganizationId()),
    staleTime: 1000 * 30,
});
const { members } = useMembersQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);

const totalSeconds = computed(() => aggregate.value?.data.seconds ?? 0);

const rows = computed<MemberTimeRow[]>(() => {
    const membersByUser = new Map(members.value.map((member) => [member.user_id, member]));
    return (aggregate.value?.data.grouped_data ?? [])
        .filter((group) => group.key !== null && group.seconds > 0)
        .map((group) => {
            const member = membersByUser.get(group.key!);
            return {
                userId: group.key!,
                memberId: member?.id ?? null,
                name: member?.name ?? '',
                seconds: group.seconds,
            };
        })
        .sort((a, b) => b.seconds - a.seconds);
});

function formatSeconds(seconds: number): string {
    return formatHumanReadableDuration(
        seconds,
        organization.value?.interval_format,
        organization.value?.number_format
    );
}

function share(seconds: number): string {
    return totalSeconds.value > 0 ? `${Math.round((seconds / totalSeconds.value) * 100)}%` : '--';
}

function reportHref(row: MemberTimeRow): string | undefined {
    if (!row.memberId) return undefined;
    return route('reporting.detailed', {
        project: props.projectId,
        member: row.memberId,
        range: 'all',
    });
}

const columnSizing = useStorage<ColumnSizingState>(
    'project-member-time-column-sizing',
    {},
    undefined,
    { eventFilter: debounceFilter(250) }
);

const columns: ColumnDef<MemberTimeRow>[] = [
    { id: 'member', header: 'Member', size: 220, minSize: 100 },
    { id: 'time', header: 'Time', size: 120, minSize: 80, meta: { align: 'right' } },
    { id: 'share', header: 'Share', size: 80, minSize: 60, meta: { align: 'right' } },
];

const table = useVueTable<MemberTimeRow>({
    get data() {
        return rows.value;
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
                data-testid="project_member_time_table"
                class="grid min-w-full"
                :style="gridTemplate">
                <DetailedReportTableHeading :headers="headers"></DetailedReportTableHeading>
                <TableRow v-for="row in rows" :key="row.userId" :href="reportHref(row)">
                    <div class="py-2.5 pr-3 pl-4 sm:pl-6 lg:pl-8 text-sm font-medium truncate">
                        {{ row.name }}
                    </div>
                    <div class="py-2.5 px-3 text-sm text-right tabular-nums">
                        {{ formatSeconds(row.seconds) }}
                    </div>
                    <div class="py-2.5 px-3 text-sm text-right tabular-nums">
                        {{ share(row.seconds) }}
                    </div>
                    <div></div>
                </TableRow>
                <div
                    v-if="!isLoading && rows.length === 0"
                    class="col-span-full py-8 text-center text-sm text-text-secondary">
                    No time tracked on this project yet.
                </div>
            </div>
        </div>
    </div>
</template>
