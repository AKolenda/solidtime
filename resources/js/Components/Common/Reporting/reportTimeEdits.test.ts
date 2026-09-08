import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Organization, TimeEntry } from '@/packages/api/src';
import { changeEntryDate, changeEntryDuration, changeEntryTimes } from './reportTimeEdits';

function entry(start: string, end: string | null): TimeEntry {
    return {
        id: 'entry',
        description: null,
        start,
        end,
        duration: end ? 3600 : null,
        project_id: null,
        task_id: null,
        organization_id: 'org',
        user_id: 'user',
        tags: [],
        billable: false,
        type: 'work',
    };
}

describe('report time edits', () => {
    beforeEach(() => {
        vi.mocked(window.getTimezoneSetting).mockReturnValue('UTC');
    });

    it('moves an overnight entry to another date while preserving its wall clock and duration', () => {
        const original = entry('2026-08-10T23:30:00Z', '2026-08-11T01:15:00Z');

        expect(changeEntryDate(original, '2026-08-20')).toEqual({
            start: '2026-08-20T23:30:00Z',
            end: '2026-08-21T01:15:00Z',
        });
        expect(original.start).toBe('2026-08-10T23:30:00Z');
    });

    it('uses the configured timezone when moving a date', () => {
        vi.mocked(window.getTimezoneSetting).mockReturnValue('America/Edmonton');
        const original = entry('2026-01-10T16:15:00Z', '2026-01-10T17:45:00Z');

        expect(changeEntryDate(original, '2026-07-10')).toEqual({
            start: '2026-07-10T15:15:00Z',
            end: '2026-07-10T16:45:00Z',
        });
    });

    it('converts edited local times to UTC and supports overnight ranges', () => {
        vi.mocked(window.getTimezoneSetting).mockReturnValue('America/Edmonton');

        expect(
            changeEntryTimes(
                entry('2026-08-10T18:00:00Z', '2026-08-10T19:00:00Z'),
                '2026-08-10T23:30',
                '2026-08-11T01:15:30'
            )
        ).toEqual({
            start: '2026-08-11T05:30:00Z',
            end: '2026-08-11T07:15:30Z',
        });
    });

    it('keeps a running entry running and rejects reversed finished ranges', () => {
        expect(
            changeEntryTimes(entry('2026-08-10T18:00:00Z', null), '2026-08-10T12:30', null)
        ).toEqual({ start: '2026-08-10T12:30:00Z', end: null });
        expect(() =>
            changeEntryTimes(
                entry('2026-08-10T18:00:00Z', '2026-08-10T19:00:00Z'),
                '2026-08-10T12:30',
                '2026-08-10T12:29'
            )
        ).toThrow('End time must not be before start time');
    });

    it('moves the end using decimal organization duration settings', () => {
        const organization = {
            number_format: 'point-comma',
            interval_format: 'decimal',
        } as Organization;

        expect(
            changeEntryDuration(
                entry('2026-08-10T18:00:00Z', '2026-08-10T19:00:00Z'),
                '1,5',
                organization
            )
        ).toEqual({
            start: '2026-08-10T18:00:00Z',
            end: '2026-08-10T19:30:00Z',
        });
    });

    it('rejects invalid, zero, and running-entry durations', () => {
        const finished = entry('2026-08-10T18:00:00Z', '2026-08-10T19:00:00Z');

        expect(() => changeEntryDuration(finished, 'nonsense')).toThrow(
            'Duration must be greater than zero'
        );
        expect(() => changeEntryDuration(finished, '0')).toThrow(
            'Duration must be greater than zero'
        );
        expect(() => changeEntryDuration(entry('2026-08-10T18:00:00Z', null), '30')).toThrow(
            'Duration cannot be changed while the entry is running'
        );
    });
});
