import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import TaskMultiselectDropdown from './TaskMultiselectDropdown.vue';

const taskFixtures = vi.hoisted(() => [
    { id: 'milling-a-1', name: 'Milling - Programming', project_id: 'project-a' },
    { id: 'milling-a-2', name: 'Milling - Programming', project_id: 'project-a' },
    { id: 'turning-a', name: 'Turning - Programming', project_id: 'project-a' },
    { id: 'milling-b', name: 'Milling - Programming', project_id: 'project-b' },
    { id: 'inspection-b', name: 'Inspection', project_id: 'project-b' },
]);

vi.mock('@/utils/useTasksQuery', () => ({
    useTasksQuery: () => ({ tasks: { value: taskFixtures } }),
}));

afterEach(() => {
    document.body.innerHTML = '';
});

function optionNames(): string[] {
    return [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].map((option) =>
        option.textContent!.trim()
    );
}

describe('TaskMultiselectDropdown', () => {
    it('renders unique organization task names and narrows them to selected projects', async () => {
        const wrapper = mount(TaskMultiselectDropdown, {
            attachTo: document.body,
            props: {
                modelValue: [],
                projectIds: [],
            },
            slots: {
                trigger: '<button>Tasks</button>',
            },
        });

        await wrapper.get('button').trigger('click');
        await nextTick();

        expect(optionNames()).toEqual([
            'No Task',
            'Inspection',
            'Milling - Programming',
            'Turning - Programming',
        ]);

        const millingOption = [
            ...document.body.querySelectorAll<HTMLElement>('[role="option"]'),
        ].find((option) => option.textContent?.trim() === 'Milling - Programming');
        millingOption?.click();
        await nextTick();

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            ['milling-a-1', 'milling-a-2', 'milling-b'],
        ]);

        await wrapper.setProps({ projectIds: ['project-b'] });
        await nextTick();

        expect(optionNames()).toEqual(['No Task', 'Inspection', 'Milling - Programming']);

        wrapper.unmount();
    });
});
