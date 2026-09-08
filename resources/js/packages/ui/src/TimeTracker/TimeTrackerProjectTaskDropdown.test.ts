/* eslint-disable vue/one-component-per-file */
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, onMounted } from 'vue';
import TimeTrackerProjectTaskDropdown from './TimeTrackerProjectTaskDropdown.vue';
import type { Client, Project, Task } from '@/packages/api/src';

vi.mock('@/utils/useUser', () => ({
    getCurrentUserId: () => 'user-test',
}));

const DropdownStub = defineComponent({
    props: {
        modelValue: {
            type: Boolean,
            default: false,
        },
    },
    emits: ['update:modelValue'],
    setup(_, { emit, slots }) {
        onMounted(() => emit('update:modelValue', true));
        return () => h('div', [slots.trigger?.(), slots.content?.()]);
    },
});

const FocusTrapStub = defineComponent({
    setup(_, { slots }) {
        return () => h('div', slots.default?.());
    },
});

function mountDropdown(props: Record<string, unknown> = {}) {
    return mount(TimeTrackerProjectTaskDropdown, {
        props: {
            project: null,
            task: null,
            projects: [] as Project[],
            tasks: [] as Task[],
            clients: [] as Client[],
            createProject: vi.fn(),
            createClient: vi.fn(),
            currency: 'EUR',
            enableEstimatedTime: false,
            organizationBillableRate: null,
            canCreateProject: false,
            ...props,
        },
        global: {
            stubs: {
                Dropdown: DropdownStub,
                UseFocusTrap: FocusTrapStub,
            },
        },
    });
}

async function openDropdown() {
    const wrapper = mountDropdown();
    await nextTick();
    await nextTick();
    return wrapper;
}

describe('TimeTrackerProjectTaskDropdown', () => {
    beforeEach(() => {
        localStorage.clear();
        HTMLElement.prototype.setPointerCapture = vi.fn();
        HTMLElement.prototype.hasPointerCapture = vi.fn(() => true);
        HTMLElement.prototype.releasePointerCapture = vi.fn();
    });

    it('resizes by pointer and saves the size for the current user', async () => {
        const wrapper = await openDropdown();
        const handle = wrapper.find('[aria-label="Resize dropdown"]');

        await handle.trigger('pointerdown', {
            button: 0,
            clientX: 400,
            clientY: 350,
            pointerId: 1,
        });
        handle.element.dispatchEvent(
            new PointerEvent('pointermove', { clientX: 500, clientY: 450, pointerId: 1 })
        );
        await nextTick();

        expect(JSON.parse(localStorage.getItem('project-picker-size:user-test') ?? '')).toEqual({
            width: 500,
            height: 450,
        });
        expect(
            wrapper.find('[data-testid="project-picker-results"]').attributes('style')
        ).toContain('width: 500px');
    });

    it('resizes with arrow keys and persists the clamped dimensions', async () => {
        const wrapper = await openDropdown();
        const handle = wrapper.get('[aria-label="Resize dropdown"]');

        await handle.trigger('keydown', { key: 'ArrowRight' });
        await handle.trigger('keydown', { key: 'ArrowDown' });

        expect(JSON.parse(localStorage.getItem('project-picker-size:user-test') ?? '')).toEqual({
            width: 420,
            height: 370,
        });

        localStorage.setItem(
            'project-picker-size:user-test',
            JSON.stringify({ width: 1000, height: 800 })
        );
        const clampedWrapper = await openDropdown();
        await clampedWrapper.get('[aria-label="Resize dropdown"]').trigger('keydown', {
            key: 'ArrowRight',
        });

        expect(JSON.parse(localStorage.getItem('project-picker-size:user-test') ?? '')).toEqual({
            width: window.innerWidth - 48,
            height: window.innerHeight - 140,
        });
    });

    it('clamps a saved size to the current viewport', async () => {
        localStorage.setItem(
            'project-picker-size:user-test',
            JSON.stringify({ width: 4000, height: 4000 })
        );
        const wrapper = await openDropdown();
        const style = wrapper.find('[data-testid="project-picker-results"]').attributes('style');

        expect(style).toContain(`width: ${window.innerWidth - 48}px`);
        expect(style).toContain(`height: ${window.innerHeight - 140}px`);
    });

    it('fits narrow viewports below the preferred minimum and ignores malformed sizes', async () => {
        const originalWidth = window.innerWidth;
        const originalHeight = window.innerHeight;
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: 260 });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 200 });
        window.dispatchEvent(new Event('resize'));
        localStorage.setItem(
            'project-picker-size:user-test',
            JSON.stringify({ width: 400, height: 400 })
        );

        const narrowWrapper = await openDropdown();
        const narrowStyle = narrowWrapper
            .find('[data-testid="project-picker-results"]')
            .attributes('style');
        expect(narrowStyle).toContain('width: 212px');
        expect(narrowStyle).toContain('height: 60px');
        narrowWrapper.unmount();

        localStorage.setItem(
            'project-picker-size:user-test',
            JSON.stringify({ width: 'wide', height: null })
        );
        const malformedWrapper = await openDropdown();
        const malformedStyle =
            malformedWrapper.find('[data-testid="project-picker-results"]').attributes('style') ??
            '';
        expect(malformedStyle).not.toContain('width:');
        expect(malformedStyle).not.toContain('height:');

        Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: originalHeight });
        window.dispatchEvent(new Event('resize'));
    });
    it('keeps the existing empty-string no-project value by default', async () => {
        const wrapper = await openDropdown();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('update:project')?.at(-1)).toEqual(['']);
        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['', null]);
    });

    it('can emit null for no-project consumers that use null as the domain value', async () => {
        const wrapper = mountDropdown({ project: 'p-1', noProjectValue: null });
        await nextTick();
        await nextTick();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('update:project')?.at(-1)).toEqual([null]);
        expect(wrapper.emitted('changed')?.at(-1)).toEqual([null, null]);
    });

    it('still exposes "No Project" when projects are empty and project creation is allowed', async () => {
        const wrapper = mountDropdown({ canCreateProject: true });
        await nextTick();
        await nextTick();

        await wrapper.find('[data-project-id=""]').trigger('click');

        expect(wrapper.emitted('changed')?.at(-1)).toEqual(['', null]);
    });

    it("keeps a project's tasks visible when the search term matches the project name", async () => {
        const project = {
            id: 'p-dummy',
            name: 'dummy',
            color: '#fff',
            client_id: null,
            is_archived: false,
        } as unknown as Project;
        const tasks = [
            { id: 't-1', name: 'design', project_id: 'p-dummy', is_done: false },
            { id: 't-2', name: 'build', project_id: 'p-dummy', is_done: false },
        ] as unknown as Task[];

        const wrapper = mountDropdown({ projects: [project], tasks });
        await nextTick();
        await nextTick();

        const searchInput = wrapper.find('[data-testid="client_dropdown_search"]');
        await searchInput.setValue('dummy');
        await nextTick();

        // project itself shows up
        expect(wrapper.find('[data-project-id="p-dummy"]').exists()).toBe(true);
        // and its tasks are still available even though they don't match "dummy":
        // the task expander keeps showing all of the project's tasks
        expect(wrapper.text()).toContain('2 Tasks');
    });
});
