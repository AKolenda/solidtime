import { flushPromises, shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TimeEntryEditModal from './TimeEntryEditModal.vue';
import TimeTrackerProjectTaskDropdown from '../TimeTracker/TimeTrackerProjectTaskDropdown.vue';
import type { Project, Task, TimeEntry } from '@/packages/api/src';

const entry: TimeEntry = {
    id: 'entry-a',
    description: 'Turning',
    start: '2026-08-21T07:00:00Z',
    end: '2026-08-21T09:00:00Z',
    duration: 7200,
    project_id: 'project-a',
    task_id: 'setup',
    organization_id: 'org',
    user_id: 'worker',
    tags: ['tag'],
    billable: false,
    type: 'work',
};
const tasks: Task[] = ['setup', 'run'].map((id) => ({
    id,
    name: id === 'setup' ? 'Setup' : 'Run',
    project_id: 'project-a',
    is_done: id === 'run',
    estimated_time: null,
    spent_time: 0,
    created_at: '',
    updated_at: '',
}));

function mountModal(timeEntry = entry, relatedTimeEntries: TimeEntry[] = []) {
    const updateTimeEntry = vi.fn().mockResolvedValue(undefined);
    const wrapper = shallowMount(TimeEntryEditModal, {
        props: {
            show: true,
            timeEntry,
            relatedTimeEntries,
            tasks,
            projects: [
                { id: 'project-a', is_billable: true },
                { id: 'project-b', is_billable: true },
            ] as Project[],
            clients: [],
            tags: [],
            updateTimeEntry,
            deleteTimeEntry: vi.fn(),
            createClient: vi.fn(),
            createProject: vi.fn(),
            createTag: vi.fn(),
            enableEstimatedTime: false,
            currency: 'CAD',
            organizationBillableRate: null,
            canCreateProject: false,
        },
        global: {
            renderStubDefaultSlot: true,
            stubs: {
                DialogModal: { template: '<div><slot name="content"/><slot name="footer"/></div>' },
                PrimaryButton: { template: '<button><slot/></button>' },
                FieldLabel: { template: '<label><slot/></label>' },
            },
        },
    });
    return { wrapper, updateTimeEntry };
}

describe('TimeEntryEditModal task corrections', () => {
    it('offers completed tasks and saves a task correction without changing the time or project', async () => {
        const { wrapper, updateTimeEntry } = mountModal();
        expect(wrapper.get('label[for="time-entry-task"]').text()).toBe('Task');
        await wrapper.get('#time-entry-task').setValue('run');
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(updateTimeEntry).toHaveBeenCalledWith({ ...entry, task_id: 'run' });
        expect(entry.task_id).toBe('setup');
    });

    it('can clear the task and only offers tasks belonging to the selected project', async () => {
        const { wrapper, updateTimeEntry } = mountModal();
        await wrapper.get('#time-entry-task').setValue('');
        await wrapper.get('button').trigger('click');
        expect(updateTimeEntry).toHaveBeenCalledWith({ ...entry, task_id: null });
        wrapper
            .findComponent(TimeTrackerProjectTaskDropdown)
            .vm.$emit('update:project', 'project-b');
        await flushPromises();
        expect(wrapper.findAll('#time-entry-task option').map((option) => option.text())).toEqual([
            'No task',
        ]);
    });

    it('selects the second underlying time entry and corrects only that entry', async () => {
        const second = {
            ...entry,
            id: 'entry-b',
            start: '2026-08-21T10:00:00Z',
            end: '2026-08-21T12:00:00Z',
        };
        const { wrapper, updateTimeEntry } = mountModal(entry, [entry, second]);
        expect(wrapper.get('#time-entry-segment').text()).toContain('10:00');
        await wrapper.get('#time-entry-segment').setValue(second.id);
        await wrapper.get('#time-entry-task').setValue('run');
        expect(wrapper.get('#time-entry-segment').attributes('disabled')).toBeDefined();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(updateTimeEntry).toHaveBeenCalledExactlyOnceWith({ ...second, task_id: 'run' });
        expect(second.task_id).toBe('setup');
    });

    it('does not offer tasks for breaks', () => {
        const { wrapper } = mountModal({
            ...entry,
            type: 'break',
            project_id: null,
            task_id: null,
        });
        expect(wrapper.find('#time-entry-task').exists()).toBe(false);
    });
});
