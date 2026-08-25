import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Project } from '@/packages/api/src';
import ProjectMoreOptionsDropdown from './ProjectMoreOptionsDropdown.vue';

vi.mock('@/utils/permissions', () => ({
    canDeleteProjects: () => true,
    canUpdateProjects: () => true,
    canViewAllTimeEntries: () => true,
}));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ProjectMoreOptionsDropdown', () => {
    it('offers a detailed PDF export action for authorized users', async () => {
        const project = {
            id: 'project-1',
            name: 'Test Project',
            is_archived: false,
        } as Project;
        const wrapper = mount(ProjectMoreOptionsDropdown, {
            attachTo: document.body,
            props: { project },
        });

        await wrapper.get('button').trigger('click');
        await nextTick();

        const exportItem = document.body.querySelector<HTMLElement>(
            '[data-testid="project_export_detailed_pdf"]'
        );
        expect(exportItem?.textContent).toContain('Export detailed PDF report');

        exportItem?.click();
        await nextTick();
        expect(wrapper.emitted('exportDetailedPdf')).toHaveLength(1);

        wrapper.unmount();
    });
});
