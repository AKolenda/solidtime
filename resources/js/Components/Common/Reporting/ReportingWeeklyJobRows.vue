<script setup lang="ts">
import { computed } from 'vue';
import type { AggregatedTimeEntries, AggregatedTimeEntriesQueryParams } from '@/packages/api/src';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useReportingStore } from '@/utils/useReporting';
import { getCurrentMembershipId, getCurrentRole } from '@/utils/useUser';

const props = defineProps<{
    memberId: string | null;
    columns: { id: string; days: string[] }[];
    start: string;
    end: string;
    jobsOnly: boolean;
    formatSeconds: (seconds: number) => string;
}>();

const isEmployee = getCurrentRole() === 'employee';

const params = computed<AggregatedTimeEntriesQueryParams>(() => ({
    start: props.start,
    end: props.end,
    group: 'project',
    sub_group: 'day',
    type: 'work',
    member_ids: !isEmployee && props.memberId ? [props.memberId] : undefined,
    member_id: isEmployee ? getCurrentMembershipId() : undefined,
}));

const { data, isPending, isError } = useAggregatedTimeEntriesQuery('weekly-jobs', params, {
    enabled: () => isEmployee || props.memberId !== null,
    keepPreviousData: false,
});

const { projects } = useProjectsQuery();
const { getNameForReportingRowEntry } = useReportingStore();

const jobs = computed(() => {
    const grouped = (data.value?.data as AggregatedTimeEntries | undefined)?.grouped_data ?? [];
    return grouped
        .filter((project) => !props.jobsOnly || project.key !== null)
        .map((project) => {
            const days = new Map<string, number>();
            for (const day of project.grouped_data ?? []) {
                if (day.key !== null) days.set(day.key, day.seconds);
            }
            const cells = props.columns.map((column) =>
                column.days.reduce((sum, day) => sum + (days.get(day) ?? 0), 0)
            );
            return {
                key: project.key ?? 'none',
                name: getNameForReportingRowEntry(project.key, 'project') ?? 'Unknown project',
                color:
                    projects.value.find((candidate) => candidate.id === project.key)?.color ??
                    'var(--color-text-quaternary)',
                cells,
                total: cells.reduce((sum, seconds) => sum + seconds, 0),
            };
        })
        .filter((job) => job.total > 0)
        .sort((a, b) => b.total - a.total);
});
</script>

<template>
    <div
        v-if="isError || isPending || jobs.length === 0"
        class="col-span-full border-b border-row-separator bg-secondary py-2.5 pl-10 sm:pl-12 lg:pl-14 text-xs text-text-tertiary">
        {{
            isError
                ? "Couldn't load jobs. Try again."
                : isPending
                  ? 'Loading jobs…'
                  : 'No job time this week'
        }}
    </div>
    <template v-else>
        <div
            v-for="job in jobs"
            :key="job.key"
            data-testid="weekly_job_row"
            class="contents text-xs text-text-secondary [&>*]:bg-secondary [&>*]:border-b [&>*]:border-row-separator [&>*]:h-[36px] [&>*]:flex [&>*]:items-center [&>*]:px-3 [&>*]:whitespace-nowrap">
            <div class="pl-10 sm:pl-12 lg:pl-14 min-w-0 gap-2 sticky left-0 z-[1]">
                <span
                    class="w-2 h-2 rounded-full shrink-0"
                    :style="{ backgroundColor: job.color }"></span>
                <span class="truncate" :title="job.name">{{ job.name }}</span>
            </div>
            <div
                v-for="(seconds, index) in job.cells"
                :key="columns[index]?.id"
                class="justify-end"
                :class="seconds <= 0 ? 'text-text-quaternary' : ''">
                {{ formatSeconds(seconds) }}
            </div>
            <div class="justify-end font-medium">{{ formatSeconds(job.total) }}</div>
            <div></div>
        </div>
    </template>
</template>
