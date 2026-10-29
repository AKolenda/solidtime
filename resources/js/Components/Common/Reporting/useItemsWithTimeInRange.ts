import { computed, type Ref } from 'vue';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { useAggregatedTimeEntriesQuery } from '@/utils/useAggregatedTimeEntriesQuery';
import { canViewAllTimeEntries } from '@/utils/permissions';
import { getCurrentMembershipId } from '@/utils/useUser';

/**
 * Projects and tasks that have time entries in the given date range, so the report filters can
 * list only those. Both sets are undefined until the range has loaded.
 */
export function useItemsWithTimeInRange(startDate: Ref<string>, endDate: Ref<string>) {
    const params = computed(() => ({
        start: getLocalizedDayJs(startDate.value).startOf('day').utc().format(),
        end: getLocalizedDayJs(endDate.value).endOf('day').utc().format(),
        member_id: !canViewAllTimeEntries() ? getCurrentMembershipId() : undefined,
        group: 'project' as const,
        sub_group: 'task' as const,
    }));

    const { data } = useAggregatedTimeEntriesQuery('filter-items-in-range', params);

    const projectIds = computed(() => {
        const groups = data.value?.data.grouped_data;
        if (!groups) return undefined;
        return new Set(groups.flatMap((group) => (group.key ? [group.key] : [])));
    });

    const taskIds = computed(() => {
        const groups = data.value?.data.grouped_data;
        if (!groups) return undefined;
        return new Set(
            groups.flatMap((group) =>
                (group.grouped_data ?? []).flatMap((task) => (task.key ? [task.key] : []))
            )
        );
    });

    return { projectIds, taskIds };
}
