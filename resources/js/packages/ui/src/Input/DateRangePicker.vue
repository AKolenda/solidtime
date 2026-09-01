<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '../popover';
import Button from '../Buttons/Button.vue';
import { RangeCalendar } from '../range-calendar';
import { CalendarDate } from '@internationalized/date';
import { CalendarIcon, Check } from '@lucide/vue';
import { computed, ref, inject, type ComputedRef, watch } from 'vue';
import { twMerge } from 'tailwind-merge';
import {
    getDayJsInstance,
    getLocalizedDayJs,
    firstDayIndex,
    type WeekStartDay,
} from '@/packages/ui/src/utils/time';
import { type Organization } from '@/packages/api/src';
import { getUserTimezone } from '@/packages/ui/src/utils/settings';
import { formatDate } from '@/packages/ui/src/utils/time';

const weekStartsOn = computed((): WeekStartDay => firstDayIndex.value as WeekStartDay);

const props = defineProps<{
    start: string;
    end: string;
}>();

const emit = defineEmits<{
    (e: 'update:start', value: string): void;
    (e: 'update:end', value: string): void;
    (e: 'submit'): void;
}>();

interface CalendarDateRange {
    start: CalendarDate | undefined;
    end: CalendarDate | undefined;
}

const today = computed(() => {
    const now = getDayJsInstance()();
    return new CalendarDate(now.year(), now.month() + 1, now.date());
});

const modelValue = computed<CalendarDateRange>({
    get: () => ({
        start: props.start
            ? new CalendarDate(
                  getLocalizedDayJs(props.start).year(),
                  getLocalizedDayJs(props.start).month() + 1,
                  getLocalizedDayJs(props.start).date()
              )
            : undefined,
        end: props.end
            ? new CalendarDate(
                  getLocalizedDayJs(props.end).year(),
                  getLocalizedDayJs(props.end).month() + 1,
                  getLocalizedDayJs(props.end).date()
              )
            : undefined,
    }),
    set: (newValue) => {
        if (newValue.start) {
            const date = newValue.start.toDate(getUserTimezone());
            emit('update:start', getLocalizedDayJs(date.toString()).format());
        }
        if (newValue.end) {
            const date = newValue.end.toDate(getUserTimezone());
            emit('update:end', getLocalizedDayJs(date.toString()).format());
        } else {
            emit('update:end', '');
        }
    },
});

const open = ref(false);

interface DatePreset {
    key: string;
    label: string;
    range: () => {
        start: ReturnType<typeof getLocalizedDayJs>;
        end: ReturnType<typeof getLocalizedDayJs>;
    };
}

const presets: DatePreset[] = [
    {
        key: 'today',
        label: 'Today',
        range: () => ({
            start: getLocalizedDayJs().startOf('day'),
            end: getLocalizedDayJs().endOf('day'),
        }),
    },
    {
        key: 'this-week',
        label: 'This Week',
        range: () => ({
            start: getLocalizedDayJs().startOf('week'),
            end: getLocalizedDayJs().endOf('week'),
        }),
    },
    {
        key: 'last-week',
        label: 'Last Week',
        range: () => ({
            start: getLocalizedDayJs().subtract(1, 'week').startOf('week'),
            end: getLocalizedDayJs().subtract(1, 'week').endOf('week'),
        }),
    },
    {
        key: 'last-14-days',
        label: 'Last 14 days',
        range: () => ({
            start: getLocalizedDayJs().subtract(14, 'days'),
            end: getLocalizedDayJs(),
        }),
    },
    {
        key: 'this-month',
        label: 'This Month',
        range: () => ({
            start: getLocalizedDayJs().startOf('month'),
            end: getLocalizedDayJs().endOf('month'),
        }),
    },
    {
        key: 'last-month',
        label: 'Last Month',
        range: () => ({
            start: getLocalizedDayJs().subtract(1, 'month').startOf('month'),
            end: getLocalizedDayJs().subtract(1, 'month').endOf('month'),
        }),
    },
    {
        key: 'last-30-days',
        label: 'Last 30 days',
        range: () => ({
            start: getLocalizedDayJs().subtract(30, 'days'),
            end: getLocalizedDayJs(),
        }),
    },
    {
        key: 'last-90-days',
        label: 'Last 90 days',
        range: () => ({
            start: getDayJsInstance()().subtract(90, 'days'),
            end: getDayJsInstance()(),
        }),
    },
    {
        key: 'last-12-months',
        label: 'Last 12 months',
        range: () => ({
            start: getLocalizedDayJs().subtract(12, 'months'),
            end: getLocalizedDayJs(),
        }),
    },
    {
        key: 'this-year',
        label: 'This year',
        range: () => ({
            start: getLocalizedDayJs().startOf('year'),
            end: getLocalizedDayJs().endOf('year'),
        }),
    },
    {
        key: 'last-year',
        label: 'Last year',
        range: () => ({
            start: getLocalizedDayJs().subtract(1, 'year').startOf('year'),
            end: getLocalizedDayJs().subtract(1, 'year').endOf('year'),
        }),
    },
];

const selectedPreset = computed(() => {
    if (!props.start || !props.end) {
        return undefined;
    }

    const selectedStart = getLocalizedDayJs(props.start);
    const selectedEnd = getLocalizedDayJs(props.end);

    return presets.find((preset) => {
        const range = preset.range();
        return selectedStart.isSame(range.start, 'day') && selectedEnd.isSame(range.end, 'day');
    });
});

function setPreset(preset: DatePreset) {
    const range = preset.range();
    emit('update:start', range.start.format());
    emit('update:end', range.end.format());
    open.value = false;
}

const organization = inject<ComputedRef<Organization>>('organization');

watch(open, (value) => {
    if (value === false) {
        emit('submit');
    }
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :class="
                    twMerge(
                        'flex w-full items-center justify-between whitespace-nowrap h-[34px] text-start',
                        !modelValue && 'text-muted-foreground'
                    )
                ">
                <CalendarIcon class="-ml-0.5 text-text-quaternary h-4 w-4" />
                <template v-if="selectedPreset">
                    {{ selectedPreset.label }}
                </template>
                <template v-else-if="modelValue.start">
                    <template v-if="modelValue.end">
                        {{ formatDate(modelValue.start.toString(), organization?.date_format) }}
                        -
                        {{ formatDate(modelValue.end.toString(), organization?.date_format) }}
                    </template>
                    <template v-else>
                        {{ formatDate(modelValue.start.toString(), organization?.date_format) }}
                    </template>
                </template>
                <template v-else> Pick a date </template>
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0">
            <div class="flex divide-x divide-border-secondary">
                <div
                    class="text-text-primary text-sm flex flex-col space-y-0.5 items-start py-2 px-2">
                    <Button
                        v-for="preset in presets"
                        :key="preset.key"
                        variant="ghost"
                        size="sm"
                        :aria-pressed="selectedPreset?.key === preset.key"
                        :class="
                            twMerge(
                                'w-full justify-between',
                                selectedPreset?.key === preset.key &&
                                    'bg-card-background-active font-medium'
                            )
                        "
                        @click="setPreset(preset)">
                        <span>{{ preset.label }}</span>
                        <Check
                            v-if="selectedPreset?.key === preset.key"
                            class="h-4 w-4 text-accent-500" />
                    </Button>
                </div>
                <div class="pl-2">
                    <RangeCalendar
                        v-model="modelValue"
                        initial-focus
                        :number-of-months="2"
                        :max-value="today"
                        :week-starts-on="weekStartsOn" />
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
