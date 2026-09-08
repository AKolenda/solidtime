import type { TimeEntriesQueryParams, TimeEntry, TimeEntryResponse } from '@/packages/api/src';

export async function fetchUnroundedReportEntries(
    ids: string[],
    queries: TimeEntriesQueryParams,
    fetchPage: (queries: TimeEntriesQueryParams) => Promise<TimeEntryResponse>
): Promise<TimeEntry[]> {
    if (ids.length === 0) return [];

    const {
        rounding_type: _roundingType,
        rounding_minutes: _roundingMinutes,
        ...rawQueries
    } = queries;
    const requestedIds = new Set(ids);
    const entriesById = new Map<string, TimeEntry>();
    let offset = rawQueries.offset ?? 0;

    while (entriesById.size < requestedIds.size) {
        const response = await fetchPage({ ...rawQueries, offset });

        for (const entry of response.data) {
            if (requestedIds.has(entry.id)) entriesById.set(entry.id, entry);
        }

        if (entriesById.size === requestedIds.size || response.data.length === 0) break;

        offset += response.data.length;
        if (offset >= response.meta.total) break;
    }

    const missingIds = [...requestedIds].filter((id) => !entriesById.has(id));
    if (missingIds.length > 0) {
        throw new Error(
            'Unable to load the selected time entries. Refresh the report and try again.'
        );
    }

    return ids.map((id) => entriesById.get(id)!);
}
