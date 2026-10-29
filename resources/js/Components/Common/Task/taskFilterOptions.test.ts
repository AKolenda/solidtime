import { describe, expect, it } from 'vitest';
import type { Task } from '@/packages/api/src';
import {
    buildTaskFilterOptions,
    getSelectedTaskOptionKeys,
    getTaskIdsForOptionKeys,
} from './taskFilterOptions';

function task(id: string, name: string, projectId: string): Task {
    return {
        id,
        name,
        project_id: projectId,
    } as Task;
}

describe('task filter options', () => {
    const tasks = [
        task('turning-a', 'Turning - Programming', 'project-a'),
        task('milling-a-1', 'Milling - Programming', 'project-a'),
        task('milling-a-2', 'Milling - Programming', 'project-a'),
        task('milling-b', 'Milling - Programming', 'project-b'),
        task('inspection-b', 'Inspection', 'project-b'),
    ];

    it('shows each task name once across the organization and selects every matching task id', () => {
        const options = buildTaskFilterOptions(tasks, []);
        const milling = options.find((option) => option.name === 'Milling - Programming');

        expect(options.map((option) => option.name)).toEqual([
            'Inspection',
            'Milling - Programming',
            'Turning - Programming',
        ]);
        expect(milling?.taskIds).toEqual(['milling-a-1', 'milling-a-2', 'milling-b']);
        expect(getTaskIdsForOptionKeys(options, [milling!.key])).toEqual([
            'milling-a-1',
            'milling-a-2',
            'milling-b',
        ]);
    });

    it('only shows unique task names from the selected project', () => {
        const options = buildTaskFilterOptions(tasks, ['project-a']);

        expect(options.map((option) => option.name)).toEqual([
            'Milling - Programming',
            'Turning - Programming',
        ]);
        expect(options[0]?.taskIds).toEqual(['milling-a-1', 'milling-a-2']);
    });

    it('keeps a grouped option selected when any matching task id is selected', () => {
        const options = buildTaskFilterOptions(tasks, ['project-a']);

        expect(getSelectedTaskOptionKeys(options, ['milling-a-2'])).toEqual([options[0]?.key]);
    });

    it('dates a grouped option by its latest entry and marks it done only when every task is', () => {
        const options = buildTaskFilterOptions(
            [
                {
                    ...task('milling-a', 'Milling', 'project-a'),
                    is_done: true,
                    last_activity_at: '2026-10-20T08:00:00Z',
                },
                {
                    ...task('milling-b', 'Milling', 'project-b'),
                    is_done: false,
                    last_activity_at: '2026-10-27T08:00:00Z',
                },
                {
                    ...task('deburr-a', 'Deburr', 'project-a'),
                    is_done: true,
                    last_activity_at: null,
                },
            ],
            []
        );

        expect(options).toMatchObject([
            { name: 'Deburr', lastActivityAt: null, isDone: true },
            { name: 'Milling', lastActivityAt: '2026-10-27T08:00:00Z', isDone: false },
        ]);
    });
});
