import type { Project } from '@/packages/api/src';

export function projectMatchesSearch(
    project: Project,
    search: string,
    clientName: string | undefined,
    taskNames: string[]
): boolean {
    const terms = search
        .trim()
        .toLocaleLowerCase()
        .split(/\s+/)
        .filter(Boolean);

    if (terms.length === 0) return true;

    const searchableText = [
        project.name,
        clientName ?? 'no client',
        ...taskNames,
        project.is_archived ? 'archived' : 'active',
        project.is_public ? 'public' : 'private',
        project.is_billable ? 'billable' : 'non billable',
    ]
        .join(' ')
        .toLocaleLowerCase();

    return terms.every((term) => searchableText.includes(term));
}
