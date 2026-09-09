import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { TimeEntry } from '@/packages/api/src';
import DetailedReportEditableCell from './DetailedReportEditableCell.vue';
import DetailedReportFieldEditor from './DetailedReportFieldEditor.vue';
import type { ReportEditableField, ReportEntryEditorContext } from './reportEntryEditing';

const rounded: TimeEntry = {
    id: 'entry',
    start: '2026-08-21T07:00:00Z',
    end: '2026-08-21T09:00:00Z',
    duration: 7200,
    description: 'Turning',
    project_id: 'project',
    task_id: 'setup',
    user_id: 'worker',
    organization_id: 'org',
    billable: false,
    tags: [],
    type: 'work',
};
const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    document.body.innerHTML = '';
});

function render(
    field: ReportEditableField,
    overrides: Partial<ReportEntryEditorContext> = {},
    entry = rounded
) {
    const context: ReportEntryEditorContext = {
        projects: [],
        tasks: [],
        clients: [],
        tags: [],
        members: [],
        organization: undefined,
        userId: 'worker',
        canChangeMember: false,
        canEdit: () => true,
        update: vi.fn(),
        ...overrides,
    };
    const wrapper = mount(DetailedReportEditableCell, {
        attachTo: document.body,
        props: { field, entries: [entry], context },
        slots: { default: 'Current value' },
        global: {
            stubs: {
                DetailedReportFieldEditor: true,
            },
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}

describe('DetailedReportEditableCell', () => {
    it('loads raw times before opening a temporal editor', async () => {
        const raw = { ...rounded, start: '2026-08-21T07:00:37Z', end: '2026-08-21T08:56:51Z' };
        let resolve!: (entries: TimeEntry[]) => void;
        const loadOriginalEntries = vi.fn(
            () =>
                new Promise<TimeEntry[]>((done) => {
                    resolve = done;
                })
        );
        const wrapper = render('time', { loadOriginalEntries });
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(loadOriginalEntries).toHaveBeenCalledExactlyOnceWith(['entry']);
        expect(document.querySelector('[role="status"]')?.textContent).toContain(
            'Loading original times'
        );
        expect(wrapper.findComponent(DetailedReportFieldEditor).exists()).toBe(false);
        resolve([raw]);
        await flushPromises();
        expect(wrapper.getComponent(DetailedReportFieldEditor).props('entries')).toEqual([raw]);
    });

    it('does not fall back to rounded values when raw loading fails', async () => {
        const wrapper = render('duration', {
            loadOriginalEntries: vi.fn().mockRejectedValue(new Error('offline')),
        });
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(wrapper.findComponent(DetailedReportFieldEditor).exists()).toBe(false);
        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'Could not load original times'
        );
    });

    it('edits duration in the cell using raw times and saves once on Enter and blur', async () => {
        const update = vi.fn().mockResolvedValue(undefined);
        const raw = { ...rounded, start: '2026-08-21T07:00:37Z', end: '2026-08-21T08:56:51Z' };
        const wrapper = render('duration', {
            update,
            loadOriginalEntries: vi.fn().mockResolvedValue([raw]),
        });
        await wrapper.get('button').trigger('click');
        await flushPromises();
        const input = wrapper.get('input[aria-label="Duration"]');
        expect((input.element as HTMLInputElement).value).toBe('1h 56min');
        expect(document.querySelector('[data-reka-popper-content-wrapper]')).toBeNull();
        await input.setValue('01:15:00');
        await input.trigger('keydown', { key: 'Enter' });
        await input.trigger('blur');
        await flushPromises();
        expect(update).toHaveBeenCalledExactlyOnceWith(['entry'], {
            start: raw.start,
            end: '2026-08-21T08:15:37Z',
        });
    });

    it('saves duration on blur, cancels with Escape, and keeps invalid input editable', async () => {
        const update = vi.fn().mockResolvedValue(undefined);
        const wrapper = render('duration', { update });
        await wrapper.get('button').trigger('click');
        await flushPromises();
        await wrapper.get('input').setValue('00:30:00');
        await wrapper.get('input').trigger('keydown', { key: 'Escape' });
        await flushPromises();
        expect(update).not.toHaveBeenCalled();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        await wrapper.get('input').setValue('invalid');
        await wrapper.get('input').trigger('blur');
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(update).not.toHaveBeenCalled();
        await wrapper.get('input').setValue('00:30:00');
        await wrapper.get('input').trigger('blur');
        await flushPromises();
        expect(update).toHaveBeenCalledExactlyOnceWith(['entry'], {
            start: rounded.start,
            end: '2026-08-21T07:30:00Z',
        });
    });

    it('opens metadata directly and respects ownership and break restrictions', async () => {
        const loadOriginalEntries = vi.fn();
        const wrapper = render('task', { loadOriginalEntries });
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(loadOriginalEntries).not.toHaveBeenCalled();
        expect(wrapper.getComponent(DetailedReportFieldEditor).props('field')).toBe('task');
        expect(
            render('project', { canEdit: () => false })
                .find('button')
                .exists()
        ).toBe(false);
        expect(render('member').find('button').exists()).toBe(false);
        expect(
            render('project', {}, { ...rounded, type: 'break', project_id: null, task_id: null })
                .find('button')
                .exists()
        ).toBe(false);
    });
});
