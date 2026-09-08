import type {
    Client,
    Member,
    Organization,
    Project,
    Tag,
    Task,
    TimeEntry,
} from '@/packages/api/src';

export type ReportEditableField =
    | 'date'
    | 'member'
    | 'description'
    | 'project'
    | 'task'
    | 'client'
    | 'tags'
    | 'billable'
    | 'time'
    | 'duration';

export type ReportEntryChanges = Partial<
    Pick<
        TimeEntry,
        'description' | 'project_id' | 'task_id' | 'tags' | 'billable' | 'start' | 'end'
    >
> & { member_id?: string };

export interface ReportEntryEditorContext {
    projects: Project[];
    tasks: Task[];
    clients: Client[];
    tags: Tag[];
    members: Member[];
    organization: Organization | undefined;
    userId: string;
    canChangeMember: boolean;
    canEdit: (entry: TimeEntry) => boolean;
    loadOriginalEntries?: (ids: string[]) => Promise<TimeEntry[]>;
    update: (ids: string[], changes: ReportEntryChanges) => Promise<void>;
}
