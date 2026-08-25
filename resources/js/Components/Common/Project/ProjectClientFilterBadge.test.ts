import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Client } from '@/packages/api/src';
import ProjectClientFilterBadge from './ProjectClientFilterBadge.vue';

function clients(count: number): Client[] {
    return Array.from({ length: count }, (_, index) => ({
        id: `client-${index}`,
        name: `Client ${index}`,
        is_archived: false,
    })) as Client[];
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('ProjectClientFilterBadge', () => {
    it('keeps a long selected-client menu bounded and scrollable', async () => {
        const wrapper = mount(ProjectClientFilterBadge, {
            attachTo: document.body,
            props: {
                value: ['client-0'],
                clients: clients(40),
            },
        });

        await wrapper.findAll('button')[0]!.trigger('click');
        await nextTick();

        const menu = document.body.querySelector<HTMLElement>('[role="menu"]');

        expect(menu).not.toBeNull();
        expect(menu?.classList.contains('max-h-[300px]')).toBe(true);
        expect(menu?.classList.contains('overflow-y-auto')).toBe(true);

        wrapper.unmount();
    });
});
