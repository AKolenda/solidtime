import { shallowMount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DetailedReportTable from './DetailedReportTable.vue';
import DetailedReportTableRow from './DetailedReportTableRow.vue';
import TimeEntryEditModal from '@/packages/ui/src/TimeEntry/TimeEntryEditModal.vue';
import type { TimeEntry } from '@/packages/api/src';

const permissionState = vi.hoisted(() => ({ updateAll: true, updateOwn: true }));

vi.mock('@/utils/useProjectsQuery', () => ({
    useProjectsQuery: () => ({ projects: { value: [] } }),
}));
vi.mock('@/utils/useTasksQuery', () => ({ useTasksQuery: () => ({ tasks: { value: [] } }) }));
vi.mock('@/utils/useClientsQuery', () => ({ useClientsQuery: () => ({ clients: { value: [] } }) }));
vi.mock('@/utils/useTagsQuery', () => ({ useTagsQuery: () => ({ tags: { value: [] } }) }));
vi.mock('@/utils/useMembersQuery', () => ({ useMembersQuery: () => ({ members: { value: [] } }) }));
vi.mock('@/utils/useOrganizationQuery', () => ({
    useOrganizationQuery: () => ({ organization: { value: undefined } }),
}));
vi.mock('@/utils/useUser', () => ({
    getCurrentOrganizationId: () => 'org',
    getCurrentUserId: () => 'worker',
}));
vi.mock('@/utils/money', () => ({ getOrganizationCurrencyString: () => 'CAD' }));
vi.mock('@/utils/billing', () => ({ isAllowedToPerformPremiumAction: () => false }));
vi.mock('@/utils/permissions', () => ({
    canCreateProjects: () => false,
    canUpdateTimeEntries: () => permissionState.updateAll,
    canUpdateOwnTimeEntries: () => permissionState.updateOwn,
}));

describe('DetailedReportTable task corrections', () => {
    beforeEach(() => {
        localStorage.clear();
        permissionState.updateAll = true;
        permissionState.updateOwn = true;
    });

    it('passes every original entry to the editor and forwards the selected entry correction', async () => {
        localStorage.clear();
        const first: TimeEntry = {
            id: 'first',
            description: 'Turning',
            start: '2026-08-21T07:00:00Z',
            end: '2026-08-21T09:00:00Z',
            duration: 7200,
            project_id: 'project',
            task_id: 'setup',
            organization_id: 'org',
            user_id: 'worker',
            tags: [],
            billable: false,
            type: 'work',
        };
        const second = {
            ...first,
            id: 'second',
            start: '2026-08-21T10:00:00Z',
            end: '2026-08-21T12:00:00Z',
        };
        const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
        const wrapper = shallowMount(DetailedReportTable, {
            props: {
                timeEntries: [first, second],
                selectedTimeEntries: [],
                updateTimeEntry,
                updateTimeEntries: vi.fn(),
                deleteTimeEntries: vi.fn(),
                duplicateTimeEntry: vi.fn(),
                startTimeEntry: vi.fn(),
            },
        });
        expect(wrapper.findAllComponents(DetailedReportTableRow)).toHaveLength(1);
        wrapper.getComponent(DetailedReportTableRow).vm.$emit('edit');
        await flushPromises();
        const modal = wrapper.getComponent(TimeEntryEditModal);
        expect(modal.props('relatedTimeEntries')).toEqual([first, second]);
        expect(modal.props('timeEntry')).toEqual(first);
        const corrected = { ...second, task_id: 'run' };
        await modal.props('updateTimeEntry')(corrected);
        expect(updateTimeEntry).toHaveBeenCalledExactlyOnceWith(corrected);
        wrapper.unmount();
    });

    function entries() {
        const first = {
            id: 'first',
            description: 'Turning',
            start: '2026-08-21T07:02:00Z',
            end: '2026-08-21T09:04:00Z',
            duration: 7320,
            project_id: 'project',
            task_id: 'setup',
            organization_id: 'org',
            user_id: 'worker',
            tags: [],
            billable: false,
            type: 'work',
        } as TimeEntry;
        return [
            first,
            {
                ...first,
                id: 'second',
                start: '2026-08-21T10:02:00Z',
                end: '2026-08-21T12:04:00Z',
            },
        ];
    }

    function mountTable(overrides: Record<string, unknown> = {}) {
        return shallowMount(DetailedReportTable, {
            props: {
                timeEntries: entries(),
                selectedTimeEntries: [],
                updateTimeEntry: vi.fn().mockResolvedValue(undefined),
                updateTimeEntries: vi.fn().mockResolvedValue(undefined),
                deleteTimeEntries: vi.fn(),
                duplicateTimeEntry: vi.fn(),
                startTimeEntry: vi.fn(),
                ...overrides,
            },
        });
    }

    it('saves a task as a partial patch without report timestamps', async () => {
        const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountTable({ updateTimeEntry });
        const context = wrapper.getComponent(DetailedReportTableRow).props('editorContext');

        await context.update(['first'], { project_id: 'project', task_id: 'run' });

        expect(updateTimeEntry).toHaveBeenCalledExactlyOnceWith({
            id: 'first',
            project_id: 'project',
            task_id: 'run',
        });
        expect(updateTimeEntry.mock.calls[0]![0]).not.toHaveProperty('start');
        expect(updateTimeEntry.mock.calls[0]![0]).not.toHaveProperty('end');
    });

    it('uses the bulk callback for grouped metadata changes', async () => {
        const updateTimeEntries = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountTable({ updateTimeEntries });
        const context = wrapper.getComponent(DetailedReportTableRow).props('editorContext');

        await context.update(['first', 'second'], { description: 'Corrected' });

        expect(updateTimeEntries).toHaveBeenCalledExactlyOnceWith(['first', 'second'], {
            description: 'Corrected',
        });
    });

    it('rejects temporal changes across grouped entries', async () => {
        const updateTimeEntry = vi.fn();
        const updateTimeEntries = vi.fn();
        const wrapper = mountTable({ updateTimeEntry, updateTimeEntries });
        const context = wrapper.getComponent(DetailedReportTableRow).props('editorContext');

        await expect(
            context.update(['first', 'second'], {
                start: '2026-08-21T08:00:00Z',
                end: '2026-08-21T09:00:00Z',
            })
        ).rejects.toThrow('Choose one entry to change its time.');
        expect(updateTimeEntry).not.toHaveBeenCalled();
        expect(updateTimeEntries).not.toHaveBeenCalled();
    });

    it('loads original entries before opening the modal for rounded report rows', async () => {
        const originals = entries().map((entry) => ({
            ...entry,
            start: entry.start.replace(':02:', ':03:'),
            end: entry.end?.replace(':04:', ':05:') ?? null,
        }));
        const loadOriginalEntries = vi.fn().mockResolvedValue(originals);
        const wrapper = mountTable({ loadOriginalEntries });

        wrapper.getComponent(DetailedReportTableRow).vm.$emit('edit');
        await flushPromises();

        expect(loadOriginalEntries).toHaveBeenCalledExactlyOnceWith(['first', 'second']);
        const modal = wrapper.getComponent(TimeEntryEditModal);
        expect(modal.props('relatedTimeEntries')).toEqual(originals);
        expect(modal.props('timeEntry')).toEqual(originals[0]);
    });

    it('keeps the modal closed and reports an original-entry load failure', async () => {
        const loadOriginalEntries = vi.fn().mockRejectedValue(new Error('network'));
        const wrapper = mountTable({ loadOriginalEntries });

        wrapper.getComponent(DetailedReportTableRow).vm.$emit('edit');
        await flushPromises();

        expect(wrapper.findComponent(TimeEntryEditModal).exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe(
            'Could not load original times. Try opening the entry again.'
        );
    });

    it('allows own-entry edits with update-own and requires update-all for other members', () => {
        permissionState.updateAll = false;
        permissionState.updateOwn = true;
        const wrapper = mountTable();
        const context = wrapper.getComponent(DetailedReportTableRow).props('editorContext');

        expect(context.canEdit(entries()[0]!)).toBe(true);
        expect(context.canEdit({ ...entries()[0]!, user_id: 'someone-else' })).toBe(false);
        expect(context.canChangeMember).toBe(false);

        permissionState.updateAll = true;
        permissionState.updateOwn = false;
        const managerContext = mountTable()
            .getComponent(DetailedReportTableRow)
            .props('editorContext');
        expect(managerContext.canEdit({ ...entries()[0]!, user_id: 'someone-else' })).toBe(true);
        expect(managerContext.canChangeMember).toBe(true);
    });

    it('does not open the legacy modal without edit permission', async () => {
        permissionState.updateAll = false;
        permissionState.updateOwn = false;
        const wrapper = mountTable();
        wrapper.getComponent(DetailedReportTableRow).vm.$emit('edit');
        await flushPromises();
        expect(wrapper.findComponent(TimeEntryEditModal).exists()).toBe(false);
    });
});
