import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { computed } from 'vue';
import DateRangePicker from './DateRangePicker.vue';

describe('DateRangePicker', () => {
    it('allows a page to set a compact trigger width', () => {
        const wrapper = mount(DateRangePicker, {
            props: {
                start: '2026-01-01T00:00:00-07:00',
                end: '2026-08-27T23:59:59-06:00',
                class: 'w-72 shrink-0',
                dataTestid: 'project_date_filter',
            },
            global: {
                provide: {
                    organization: computed(() => ({ date_format: 'hyphen-separated-yyyy-mm-dd' })),
                },
                stubs: {
                    Popover: { template: '<div><slot /></div>' },
                    PopoverTrigger: { template: '<div><slot /></div>' },
                    PopoverContent: true,
                    RangeCalendar: true,
                    Button: {
                        props: ['class'],
                        template: '<button :class="$props.class"><slot /></button>',
                    },
                },
            },
        });

        const trigger = wrapper.get('button');
        expect(trigger.classes()).toContain('w-72');
        expect(trigger.classes()).toContain('shrink-0');
        expect(trigger.classes()).not.toContain('w-full');
    });
});
