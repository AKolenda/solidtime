import { describe, expect, test } from 'vitest';
import type { TimeEntry } from '@/packages/api/src';
import { collapseTimeEntries } from './collapseTimeEntries';
import type { CollapsedTimeEntry } from './collapseTimeEntries';

let idCounter = 0;

/** Indexed access with a real assertion, so `noUncheckedIndexedAccess` stays satisfied. */
function row(rows: CollapsedTimeEntry[], index: number): CollapsedTimeEntry {
    const value = rows[index];
    if (value === undefined) {
        throw new Error(`expected a collapsed row at index ${index}, got ${rows.length} rows`);
    }
    return value;
}

function entry(overrides: Partial<TimeEntry> & Record<string, unknown> = {}): TimeEntry {
    idCounter += 1;
    return {
        id: `e-${idCounter}`,
        start: '2026-04-10T09:00:00Z',
        end: '2026-04-10T10:00:00Z',
        duration: 3600,
        description: 'Milling - Programming',
        project_id: 'p-1',
        task_id: 't-1',
        member_id: 'm-1',
        client_id: 'c-1',
        organization_id: 'o-1',
        user_id: 'u-1',
        billable: true,
        tags: [],
        type: 'work',
        ...overrides,
    } as unknown as TimeEntry;
}

describe('collapseTimeEntries', () => {
    test('returns an empty array for empty input', () => {
        expect(collapseTimeEntries([])).toEqual([]);
    });

    test('collapses two identical entries into one with summed duration', () => {
        const result = collapseTimeEntries([
            entry({ id: 'a', duration: 3600 }),
            entry({ id: 'b', duration: 1800 }),
        ]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).id).toBe('a');
        expect(row(result, 0).duration).toBe(5400);
        expect(row(result, 0).collapsed_count).toBe(2);
        expect(row(result, 0).collapsed_ids).toEqual(['a', 'b']);
    });

    test('a single entry yields collapsed_count 1 and its own id', () => {
        const result = collapseTimeEntries([entry({ id: 'solo' })]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).collapsed_count).toBe(1);
        expect(row(result, 0).collapsed_ids).toEqual(['solo']);
        expect(row(result, 0).duration).toBe(3600);
    });

    test.each([
        ['project_id', { project_id: 'p-2' }],
        ['task_id', { task_id: 't-2' }],
        ['user_id', { user_id: 'u-2' }],
        ['member_id', { member_id: 'm-2' }],
        ['client_id', { client_id: 'c-2' }],
        ['description', { description: 'Turning - Setup' }],
        ['billable', { billable: false }],
    ])('entries differing by %s do not collapse', (_field, overrides) => {
        const result = collapseTimeEntries([entry({ id: 'a' }), entry({ id: 'b', ...overrides })]);

        expect(result).toHaveLength(2);
        expect(result.map((row) => row.collapsed_count)).toEqual([1, 1]);
        expect(result.map((row) => row.id)).toEqual(['a', 'b']);
    });

    describe('separating people', () => {
        /**
         * Mimics what the API actually returns: no `member_id` and no `client_id`
         * (see app/Http/Resources/V1/TimeEntry/TimeEntryResource.php), so `user_id` is the
         * only field distinguishing two operators logging the same work.
         */
        function apiEntry(id: string, userId: string): TimeEntry {
            const base = entry({ id, user_id: userId }) as unknown as Record<string, unknown>;
            delete base.member_id;
            delete base.client_id;
            return base as unknown as TimeEntry;
        }

        test('identical work from two different users does not collapse', () => {
            const result = collapseTimeEntries([
                apiEntry('op-a', 'user-alice'),
                apiEntry('op-b', 'user-bob'),
            ]);

            expect(result).toHaveLength(2);
            expect(result.map((collapsed) => collapsed.user_id)).toEqual([
                'user-alice',
                'user-bob',
            ]);
            expect(result.map((collapsed) => collapsed.collapsed_count)).toEqual([1, 1]);
            expect(result.map((collapsed) => collapsed.collapsed_ids)).toEqual([
                ['op-a'],
                ['op-b'],
            ]);
        });

        test('identical work from the same user still collapses', () => {
            const result = collapseTimeEntries([
                apiEntry('shift-1', 'user-alice'),
                apiEntry('shift-2', 'user-alice'),
            ]);

            expect(result).toHaveLength(1);
            expect(row(result, 0).user_id).toBe('user-alice');
            expect(row(result, 0).collapsed_count).toBe(2);
            expect(row(result, 0).collapsed_ids).toEqual(['shift-1', 'shift-2']);
            expect(row(result, 0).duration).toBe(7200);
        });

        test('interleaved entries from several users stay attributed to their own user', () => {
            const result = collapseTimeEntries([
                apiEntry('a1', 'user-alice'),
                apiEntry('b1', 'user-bob'),
                apiEntry('a2', 'user-alice'),
                apiEntry('c1', 'user-carol'),
                apiEntry('b2', 'user-bob'),
            ]);

            expect(result).toHaveLength(3);
            expect(result.map((collapsed) => collapsed.user_id)).toEqual([
                'user-alice',
                'user-bob',
                'user-carol',
            ]);
            expect(result.map((collapsed) => collapsed.collapsed_ids)).toEqual([
                ['a1', 'a2'],
                ['b1', 'b2'],
                ['c1'],
            ]);
        });
    });

    test('null project/task groups with other nulls but not with a real id', () => {
        const result = collapseTimeEntries([
            entry({ id: 'null-1', project_id: null, task_id: null }),
            entry({ id: 'real', project_id: 'p-1', task_id: 't-1' }),
            entry({ id: 'null-2', project_id: null, task_id: null }),
        ]);

        expect(result).toHaveLength(2);
        expect(row(result, 0).collapsed_ids).toEqual(['null-1', 'null-2']);
        expect(row(result, 0).project_id).toBeNull();
        expect(row(result, 1).collapsed_ids).toEqual(['real']);
    });

    test('treats undefined the same as null for grouping', () => {
        const result = collapseTimeEntries([
            entry({ id: 'a', project_id: null }),
            entry({ id: 'b', project_id: undefined }),
        ]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).collapsed_ids).toEqual(['a', 'b']);
    });

    test('preserves the order of first appearance of each group', () => {
        const result = collapseTimeEntries([
            entry({ id: 'z', description: 'Zeta' }),
            entry({ id: 'a', description: 'Alpha' }),
            entry({ id: 'z2', description: 'Zeta' }),
            entry({ id: 'm', description: 'Mu' }),
            entry({ id: 'a2', description: 'Alpha' }),
        ]);

        expect(result.map((row) => row.description)).toEqual(['Zeta', 'Alpha', 'Mu']);
        expect(result.map((row) => row.collapsed_ids)).toEqual([['z', 'z2'], ['a', 'a2'], ['m']]);
    });

    test('uses the earliest start and the latest end of the group', () => {
        const result = collapseTimeEntries([
            entry({
                id: 'mid',
                start: '2026-04-10T12:00:00Z',
                end: '2026-04-10T13:00:00Z',
            }),
            entry({
                id: 'early',
                start: '2026-04-10T08:00:00Z',
                end: '2026-04-10T09:00:00Z',
            }),
            entry({
                id: 'late',
                start: '2026-04-10T15:00:00Z',
                end: '2026-04-10T17:30:00Z',
            }),
        ]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).start).toBe('2026-04-10T08:00:00Z');
        expect(row(result, 0).end).toBe('2026-04-10T17:30:00Z');
    });

    test('a group containing a running entry produces a null end', () => {
        const result = collapseTimeEntries([
            entry({ id: 'done', start: '2026-04-10T08:00:00Z', end: '2026-04-10T09:00:00Z' }),
            entry({ id: 'running', start: '2026-04-10T10:00:00Z', end: null, duration: null }),
            entry({ id: 'later', start: '2026-04-10T11:00:00Z', end: '2026-04-10T12:00:00Z' }),
        ]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).end).toBeNull();
        expect(row(result, 0).start).toBe('2026-04-10T08:00:00Z');
        expect(row(result, 0).collapsed_count).toBe(3);
        expect(row(result, 0).duration).toBe(7200);
    });

    test('keeps a null duration when every entry in the group has one', () => {
        const result = collapseTimeEntries([
            entry({ id: 'r1', end: null, duration: null }),
            entry({ id: 'r2', end: null, duration: null }),
        ]);

        expect(result).toHaveLength(1);
        expect(row(result, 0).duration).toBeNull();
    });

    test('does not mutate the input array or its objects', () => {
        const first = entry({ id: 'a', duration: 3600, start: '2026-04-10T09:00:00Z' });
        const second = entry({ id: 'b', duration: 1800, start: '2026-04-10T07:00:00Z' });
        const input = [first, second];
        const snapshot = JSON.parse(JSON.stringify(input));

        const result = collapseTimeEntries(input);

        expect(input).toHaveLength(2);
        expect(JSON.parse(JSON.stringify(input))).toEqual(snapshot);
        expect(row(result, 0)).not.toBe(first);
    });

    test('keeps the remaining fields of the first entry as the base', () => {
        const result = collapseTimeEntries([
            entry({ id: 'a', tags: ['tag-1'], organization_id: 'o-9' }),
            entry({ id: 'b', tags: ['tag-2'], organization_id: 'o-9' }),
        ]);

        expect(row(result, 0).id).toBe('a');
        expect(row(result, 0).tags).toEqual(['tag-1']);
        expect(row(result, 0).organization_id).toBe('o-9');
        expect(row(result, 0).billable).toBe(true);
    });
});
