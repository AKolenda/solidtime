import { describe, expect, it, vi } from 'vitest';
import type { TimeEntriesQueryParams, TimeEntry, TimeEntryResponse } from '@/packages/api/src';
import { fetchUnroundedReportEntries } from './fetchUnroundedReportEntries';

function entry(id: string): TimeEntry {
    return {
        id,
        description: null,
        start: '2026-08-10T18:00:00Z',
        end: '2026-08-10T19:00:00Z',
        duration: 3600,
        project_id: null,
        task_id: null,
        organization_id: 'org',
        user_id: 'user',
        tags: [],
        billable: false,
        type: 'work',
    };
}

function page(ids: string[], total: number): TimeEntryResponse {
    return { data: ids.map(entry), meta: { total } } as TimeEntryResponse;
}

describe('fetchUnroundedReportEntries', () => {
    it('removes rounding, preserves filters and pagination, and returns requested ID order', async () => {
        const queries = {
            start: '2026-08-01T00:00:00Z',
            end: '2026-09-01T00:00:00Z',
            project_ids: ['project'],
            limit: 2,
            offset: 4,
            rounding_type: 'nearest',
            rounding_minutes: 15,
        } satisfies TimeEntriesQueryParams;
        const originalQueries = structuredClone(queries);
        const fetchPage = vi
            .fn<(queries: TimeEntriesQueryParams) => Promise<TimeEntryResponse>>()
            .mockResolvedValueOnce(page(['third', 'second'], 8))
            .mockResolvedValueOnce(page(['first', 'other'], 8));

        const result = await fetchUnroundedReportEntries(
            ['first', 'second', 'third'],
            queries,
            fetchPage
        );

        expect(result.map(({ id }) => id)).toEqual(['first', 'second', 'third']);
        expect(fetchPage).toHaveBeenNthCalledWith(1, {
            start: '2026-08-01T00:00:00Z',
            end: '2026-09-01T00:00:00Z',
            project_ids: ['project'],
            limit: 2,
            offset: 4,
        });
        expect(fetchPage).toHaveBeenNthCalledWith(2, {
            start: '2026-08-01T00:00:00Z',
            end: '2026-09-01T00:00:00Z',
            project_ids: ['project'],
            limit: 2,
            offset: 6,
        });
        expect(queries).toEqual(originalQueries);
    });

    it('stops at an empty page and asks the caller to refresh when an ID is missing', async () => {
        const fetchPage = vi
            .fn<(queries: TimeEntriesQueryParams) => Promise<TimeEntryResponse>>()
            .mockResolvedValueOnce(page(['present'], 10))
            .mockResolvedValueOnce(page([], 10));

        await expect(
            fetchUnroundedReportEntries(['present', 'missing'], { limit: 1, offset: 0 }, fetchPage)
        ).rejects.toThrow('Refresh the report and try again');
        expect(fetchPage).toHaveBeenCalledTimes(2);
    });

    it('stops when metadata total is reached and does not fetch for an empty selection', async () => {
        const fetchPage = vi.fn<(queries: TimeEntriesQueryParams) => Promise<TimeEntryResponse>>();
        fetchPage.mockResolvedValueOnce(page(['present'], 6));

        await expect(
            fetchUnroundedReportEntries(['missing'], { limit: 1, offset: 5 }, fetchPage)
        ).rejects.toThrow('Refresh the report and try again');
        expect(fetchPage).toHaveBeenCalledTimes(1);

        fetchPage.mockClear();
        await expect(fetchUnroundedReportEntries([], { limit: 10000 }, fetchPage)).resolves.toEqual(
            []
        );
        expect(fetchPage).not.toHaveBeenCalled();
    });
});
