import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, type PropType } from 'vue';
import type { TimeEntry } from '@/packages/api/src';
import DetailedReportFieldEditor from './DetailedReportFieldEditor.vue';
import type { ReportEditableField, ReportEntryEditorContext } from './reportEntryEditing';

const VirtualizerStub = defineComponent({
    props: {
        options: {
            type: Array as PropType<unknown[]>,
            required: true,
        },
    },
    setup(props, { slots }) {
        return () =>
            h(
                'div',
                props.options.map((option) => slots.default?.({ option }))
            );
    },
});

const firstEntry = {
    id: 'entry-a',
    description: 'Setup',
    start: '2026-08-21T07:00:00Z',
    end: '2026-08-21T09:00:00Z',
    duration: 7200,
    project_id: 'project-a',
    task_id: 'task-a',
    organization_id: 'org-a',
    user_id: 'user-a',
    tags: ['tag-a'],
    billable: false,
    type: 'work',
} as TimeEntry;

const secondEntry = {
    ...firstEntry,
    id: 'entry-b',
    start: '2026-08-21T10:00:00Z',
    end: '2026-08-21T12:00:00Z',
    tags: ['tag-b'],
} as TimeEntry;

function makeContext(update = vi.fn().mockResolvedValue(undefined)): ReportEntryEditorContext {
    return {
        projects: [
            { id: 'project-a', name: 'Alpha', client_id: 'client-a' },
            { id: 'project-b', name: 'Beta', client_id: 'client-b' },
        ],
        tasks: [
            { id: 'task-a', name: 'Active task', project_id: 'project-a', is_done: false },
            { id: 'task-done', name: 'Completed task', project_id: 'project-a', is_done: true },
            { id: 'task-b', name: 'Other project task', project_id: 'project-b', is_done: false },
        ],
        clients: [
            { id: 'client-a', name: 'Acme' },
            { id: 'client-b', name: 'Bravo' },
        ],
        tags: [
            { id: 'tag-a', name: 'Existing' },
            { id: 'tag-b', name: 'Draft' },
        ],
        members: [
            { id: 'member-a', name: 'Alex', user_id: 'user-a' },
            { id: 'member-b', name: 'Blair', user_id: 'user-b' },
        ],
        organization: undefined,
        userId: 'current-user',
        canChangeMember: true,
        canEdit: () => true,
        update,
    } as unknown as ReportEntryEditorContext;
}

function mountEditor(
    field: ReportEditableField,
    entries: TimeEntry[] = [firstEntry],
    update = vi.fn().mockResolvedValue(undefined)
) {
    const context = makeContext(update);
    const wrapper = mount(DetailedReportFieldEditor, {
        props: { field, entries, context },
        attachTo: document.body,
        global: {
            stubs: {
                ComboboxVirtualizer: VirtualizerStub,
            },
        },
    });
    return { wrapper, update };
}

async function chooseOption(wrapper: VueWrapper, id: string) {
    await wrapper.get(`[data-option-id="${id}"]`).trigger('click');
    await flushPromises();
}

describe('DetailedReportFieldEditor', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });
    it('changes project and clears a task from the previous project', async () => {
        const { wrapper, update } = mountEditor('project');

        await chooseOption(wrapper, 'project-b');

        expect(update).toHaveBeenCalledExactlyOnceWith(['entry-a'], {
            project_id: 'project-b',
            task_id: null,
        });
    });

    it('offers completed tasks and saves the project with the task', async () => {
        const { wrapper, update } = mountEditor('task');

        expect(wrapper.text()).toContain('Completed task');
        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.find('[data-option-id="task-b"]').exists()).toBe(false);
        await chooseOption(wrapper, 'task-done');

        expect(update).toHaveBeenCalledExactlyOnceWith(['entry-a'], {
            project_id: 'project-a',
            task_id: 'task-done',
        });
    });

    it('can target one grouped entry or all grouped entries', async () => {
        const { wrapper, update } = mountEditor('member', [firstEntry, secondEntry]);
        const entrySelect = wrapper.get('select');

        await entrySelect.setValue('entry-b');
        await chooseOption(wrapper, 'member-b');
        expect(update).toHaveBeenLastCalledWith(['entry-b'], { member_id: 'member-b' });

        update.mockClear();
        const allWrapper = mountEditor('member', [firstEntry, secondEntry], update).wrapper;
        await allWrapper.get('select').setValue('all');
        await chooseOption(allWrapper, 'member-b');
        expect(update).toHaveBeenCalledExactlyOnceWith(['entry-a', 'entry-b'], {
            member_id: 'member-b',
        });
    });

    it('does not offer an all-entries target for temporal fields', () => {
        const { wrapper } = mountEditor('date', [firstEntry, secondEntry]);

        expect(wrapper.find('option[value="all"]').exists()).toBe(false);
        expect(wrapper.findAll('select option')).toHaveLength(2);
    });

    it('keeps tag changes as a draft until save and discards them on cancel', async () => {
        const cancelled = mountEditor('tags');
        await chooseOption(cancelled.wrapper, 'tag-b');
        await cancelled.wrapper.get('button[type="button"]').trigger('click');
        expect(cancelled.update).not.toHaveBeenCalled();
        expect(cancelled.wrapper.emitted('close')).toHaveLength(1);

        const saved = mountEditor('tags');
        await chooseOption(saved.wrapper, 'tag-b');
        await saved.wrapper.get('button[type="submit"]').trigger('submit');
        await flushPromises();
        expect(saved.update).toHaveBeenCalledExactlyOnceWith(['entry-a'], {
            tags: ['tag-a', 'tag-b'],
        });
    });

    it('keeps the editor open and shows update errors', async () => {
        const update = vi.fn().mockRejectedValue(new Error('Correction failed'));
        const { wrapper } = mountEditor('member', [firstEntry], update);

        await chooseOption(wrapper, 'member-b');

        expect(wrapper.get('[role="alert"]').text()).toBe('Correction failed');
        expect(wrapper.emitted('close')).toBeUndefined();
        expect(wrapper.get('form').attributes('aria-busy')).toBe('false');
        expect(wrapper.find('[data-option-id="member-b"]').exists()).toBe(true);
    });
});
