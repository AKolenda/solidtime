import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ProjectMultiselectDropdown from './ProjectMultiselectDropdown.vue';

vi.mock('@/utils/useProjectsQuery', () => ({
    useProjectsQuery: () => ({ projects: [] }),
}));

vi.mock('@/utils/useUser', () => ({
    getCurrentUserId: () => 'project-filter-user',
}));

describe('ProjectMultiselectDropdown', () => {
    it('scopes its saved dimensions to the current user', () => {
        const wrapper = shallowMount(ProjectMultiselectDropdown);

        expect(wrapper.find('multiselect-dropdown-stub').attributes('resizablestoragekey')).toBe(
            'project-picker-size:project-filter-user'
        );
    });
});
