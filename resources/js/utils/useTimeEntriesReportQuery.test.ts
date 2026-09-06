import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query';
import { useTimeEntriesReportQuery } from './useTimeEntriesReportQuery';

const getTimeEntries = vi.hoisted(() => vi.fn());
vi.mock('@/packages/api/src', () => ({ api: { getTimeEntries } }));
vi.mock('@/utils/useUser', () => ({ getCurrentOrganizationId: () => 'organization' }));

afterEach(() => vi.clearAllMocks());

describe('detailed report page sizes', () => {
    it('fetches each selected page size and every batch for All, preserving filters', async () => {
        getTimeEntries.mockImplementation(async ({ queries }) => ({
            data: Array.from(
                { length: Math.min(queries.limit, 10002 - queries.offset) },
                (_, index) => ({ id: queries.offset + index })
            ),
            meta: { total: 10002 },
        }));
        const filters = ref({ limit: 25, offset: 25, project_ids: ['project'] });
        const all = ref(false);
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        const wrapper = mount(
            defineComponent({
                setup() {
                    const query = useTimeEntriesReportQuery(filters, all);
                    return { response: query.data };
                },
                template: '<div>{{ response?.data.length }}</div>',
            }),
            { global: { plugins: [[VueQueryPlugin, { queryClient: client }]] } }
        );
        try {
            await vi.waitFor(() => expect(wrapper.text()).toBe('25'));
            expect(getTimeEntries).toHaveBeenLastCalledWith({
                params: { organization: 'organization' },
                queries: { limit: 25, offset: 25, project_ids: ['project'] },
            });
            for (const limit of [50, 100]) {
                filters.value = { ...filters.value, limit, offset: 0 };
                await vi.waitFor(() => expect(wrapper.text()).toBe(String(limit)));
            }
            filters.value = { ...filters.value, limit: 10000, offset: 0 };
            all.value = true;
            await vi.waitFor(() => expect(wrapper.text()).toBe('10002'));
            expect(getTimeEntries).toHaveBeenLastCalledWith({
                params: { organization: 'organization' },
                queries: { limit: 10000, offset: 10000, project_ids: ['project'] },
            });
            filters.value = { ...filters.value, limit: 25, offset: 0 };
            all.value = false;
            await vi.waitFor(() => expect(wrapper.text()).toBe('25'));
        } finally {
            wrapper.unmount();
            client.clear();
        }
    });
});
