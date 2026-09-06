import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { api, type TimeEntryResponse } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { computed, type Ref, type ComputedRef, unref } from 'vue';

export function useTimeEntriesReportQuery(
    filterParams: Ref<Record<string, unknown>> | ComputedRef<Record<string, unknown>>,
    allEntries: Ref<boolean> = computed(() => false)
) {
    return useQuery<TimeEntryResponse>({
        queryKey: computed(() => [
            'timeEntries',
            'detailed-report',
            getCurrentOrganizationId(),
            unref(filterParams),
            unref(allEntries),
        ]),
        enabled: computed(() => !!getCurrentOrganizationId()),
        queryFn: async () => {
            const queries = { ...unref(filterParams) };
            const organization = getCurrentOrganizationId() || '';
            const fetchAll = unref(allEntries);
            const response = await api.getTimeEntries({
                params: {
                    organization,
                },
                queries,
            });
            if (!fetchAll) return response;

            const data = [...response.data];
            while (data.length < response.meta.total) {
                const nextPage = await api.getTimeEntries({
                    params: { organization },
                    queries: { ...queries, offset: data.length },
                });
                if (nextPage.data.length === 0) break;
                data.push(...nextPage.data);
            }
            return { ...response, data };
        },
        // Keep the previous page's data (incl. meta.total) while the next page loads, so
        // pagination doesn't transiently see total=1 and clamp the page back to 1.
        placeholderData: keepPreviousData,
        staleTime: 1000 * 30, // 30 seconds
    });
}
