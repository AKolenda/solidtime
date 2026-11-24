import { useQuery } from '@tanstack/vue-query';
import {
    api,
    type AggregatedTimeEntriesQueryParams,
    type ReportingResponse,
} from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { computed, type ComputedRef, unref } from 'vue';

export function useAggregatedTimeEntriesQuery(
    queryKeyPrefix: string,
    filterParams: ComputedRef<AggregatedTimeEntriesQueryParams>,
    options: { enabled?: () => boolean; keepPreviousData?: boolean } = {}
) {
    const query = useQuery<ReportingResponse>({
        queryKey: computed(() => [
            'aggregatedTimeEntries',
            queryKeyPrefix,
            getCurrentOrganizationId(),
            unref(filterParams),
        ]),
        queryFn: () =>
            api.getAggregatedTimeEntries({
                params: {
                    organization: getCurrentOrganizationId() || '',
                },
                queries: unref(filterParams),
            }),
        enabled: computed(
            () => !!getCurrentOrganizationId() && (options.enabled ? options.enabled() : true)
        ),
        placeholderData:
            options.keepPreviousData === false ? undefined : (previousData) => previousData,
        staleTime: 1000 * 30,
    });

    return query;
}
