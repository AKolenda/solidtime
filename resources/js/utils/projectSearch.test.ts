import { describe, expect, it } from 'vitest';
import { projectMatchesSearch } from './projectSearch';
import type { Project } from '@/packages/api/src';

const project = {
    name: 'Carriage Washer GM062426',
    is_archived: false,
    is_public: true,
    is_billable: true,
} as Project;

describe('projectMatchesSearch', () => {
    it('matches words across project, client, and task fields', () => {
        expect(projectMatchesSearch(project, 'carriage milling GM', 'General Motors', ['Milling']))
            .toBe(true);
    });

    it('matches project status and visibility', () => {
        expect(projectMatchesSearch(project, 'active public billable', undefined, [])).toBe(true);
        expect(projectMatchesSearch(project, 'archived', undefined, [])).toBe(false);
    });

    it('treats an empty query as a match', () => {
        expect(projectMatchesSearch(project, '   ', undefined, [])).toBe(true);
    });
});
