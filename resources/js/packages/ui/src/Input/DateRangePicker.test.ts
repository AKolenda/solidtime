import { computed, defineComponent, nextTick, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import DateRangePicker from './DateRangePicker.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('DateRangePicker', () => {
    it('shows and highlights the selected preset', async () => {
        const host = defineComponent({
            components: { DateRangePicker },
            setup() {
                const start = ref(getLocalizedDayJs().startOf('day').format());
                const end = ref(getLocalizedDayJs().endOf('day').format());
                return { start, end };
            },
            template: '<DateRangePicker v-model:start="start" v-model:end="end" />',
        });

        const wrapper = mount(host, {
            attachTo: document.body,
            global: {
                provide: {
                    organization: computed(() => ({ date_format: 'YYYY-MM-DD' })),
                },
            },
        });
        expect(wrapper.get('button').text()).toContain('Today');

        await wrapper.get('button').trigger('click');
        await nextTick();

        const lastMonth = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find(
            (button) => button.textContent?.trim() === 'Last Month'
        );
        expect(lastMonth).toBeDefined();
        lastMonth?.click();
        await nextTick();

        expect(wrapper.get('button').text()).toContain('Last Month');

        await wrapper.get('button').trigger('click');
        await nextTick();

        const selectedLastMonth = [
            ...document.body.querySelectorAll<HTMLButtonElement>('button'),
        ].some(
            (button) =>
                button.textContent?.trim() === 'Last Month' &&
                button.getAttribute('aria-pressed') === 'true'
        );
        expect(selectedLastMonth).toBe(true);

        wrapper.unmount();
    });
});
