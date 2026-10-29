import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import TaskMultiselectDropdown from './TaskMultiselectDropdown.vue';

const taskFixtures = vi.hoisted(
    () =>
        [
            { id: 'milling-a-1', name: 'Milling - Programming', project_id: 'project-a' },
            { id: 'milling-a-2', name: 'Milling - Programming', project_id: 'project-a' },
            { id: 'turning-a', name: 'Turning - Programming', project_id: 'project-a' },
            { id: 'milling-b', name: 'Milling - Programming', project_id: 'project-b' },
            { id: 'inspection-b', name: 'Inspection', project_id: 'project-b' },
        ] as Record<string, unknown>[]
);

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

    it('lists the most recently tracked tasks first and hides done ones until shown', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-28T12:00:00Z'));
        const original = taskFixtures.splice(0, taskFixtures.length);
        taskFixtures.push(
            {
                id: 'a',
                name: 'Anodize',
                project_id: 'p',
                is_done: false,
                last_activity_at: '2025-03-02T10:00:00Z',
            },
            {
                id: 'm',
                name: 'Milling',
                project_id: 'p',
                is_done: false,
                last_activity_at: '2026-10-27T10:00:00Z',
            },
            {
                id: 'd',
                name: 'Deburr',
                project_id: 'p',
                is_done: true,
                last_activity_at: '2026-10-28T10:00:00Z',
            },
            { id: 'i', name: 'Inspection', project_id: 'p', is_done: false, last_activity_at: null }
        );

        const wrapper: ReturnType<typeof mount> = mount(TaskMultiselectDropdown, {
            attachTo: document.body,
            props: {
                modelValue: [],
                'onUpdate:modelValue': (value: string[]) => wrapper.setProps({ modelValue: value }),
            },
            slots: { trigger: '<button>Tasks</button>' },
        });

        await wrapper.get('button').trigger('click');
        await nextTick();

        expect(optionNames()).toEqual([
            'No Task',
            'MillingOct 27',
            'AnodizeMar 2025',
            'Inspection',
        ]);

        const toggle = document.body.querySelector<HTMLInputElement>(
            'label input[type="checkbox"]'
        );
        expect(toggle?.parentElement?.textContent?.trim()).toBe('Show done (1)');
        toggle!.click();
        await nextTick();

        expect(optionNames()).toEqual([
            'No Task',
            'DeburrOct 28',
            'MillingOct 27',
            'AnodizeMar 2025',
            'Inspection',
        ]);

        const deburr = [...document.body.querySelectorAll<HTMLElement>('[role="option"]')].find(
            (option) => option.textContent?.startsWith('Deburr')
        );
        deburr!.click();
        await nextTick();
        toggle!.click();
        await nextTick();

        // a selected done task stays listed after hiding done ones again
        expect(optionNames()).toContain('DeburrOct 28');

        wrapper.unmount();
        taskFixtures.splice(0, taskFixtures.length, ...original);
        vi.useRealTimers();
    });
});
