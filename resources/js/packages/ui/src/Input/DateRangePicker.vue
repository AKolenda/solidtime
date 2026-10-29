<script setup lang="ts">
import { Popover, PopoverContent, PopoverTrigger } from '../popover';
import Button from '../Buttons/Button.vue';
import { RangeCalendar } from '../range-calendar';
import { CalendarDate } from '@internationalized/date';
import { CalendarIcon, Check } from '@lucide/vue';
import { computed, ref, inject, type ComputedRef, watch } from 'vue';
import { twMerge } from 'tailwind-merge';
import DateRangeNavigator from './DateRangeNavigator.vue';
import {
    getLocalizedDayJs,
    getLocalizedDayJsFromMinutes,
    firstDayIndex,
    formatDate,
    parseDateInput,
    type WeekStartDay,
} from '@/packages/ui/src/utils/time';
import { getDateRangeLabel, shiftDateRange, type DateRangeDirection } from '../utils/dateRange';
import { type Organization } from '@/packages/api/src';
import { getUserTimezone } from '@/packages/ui/src/utils/settings';

const weekStartsOn = computed((): WeekStartDay => firstDayIndex.value as WeekStartDay);

const props = withDefaults(
    defineProps<{
        start: string;
        end: string;
        allowFuture?: boolean;
    }>(),
    { allowFuture: false }
);

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
    const now = getLocalizedDayJs();
    return new CalendarDate(now.year(), now.month() + 1, now.date());
});

const startDay = computed(() => (props.start ? getLocalizedDayJs(props.start) : undefined));
const endDay = computed(() => (props.end ? getLocalizedDayJs(props.end) : undefined));

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
            start: getLocalizedDayJs().subtract(13, 'days').startOf('day'),
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
            start: getLocalizedDayJs().subtract(29, 'days').startOf('day'),
            end: getLocalizedDayJs(),
        }),
    },
    {
        key: 'last-90-days',
        label: 'Last 90 days',
        range: () => ({
            start: getLocalizedDayJs().subtract(89, 'days').startOf('day'),
            end: getLocalizedDayJs(),
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
    {
        key: 'all-time',
        label: 'All time',
        range: () => ({
            start: getLocalizedDayJs('2000-01-01').startOf('day'),
            end: getLocalizedDayJs().endOf('day'),
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

const displayLabel = computed(() => {
    if (selectedPreset.value) return selectedPreset.value.label;
    if (!startDay.value) return 'Pick a date';
    if (!endDay.value) {
        return getDateRangeLabel(startDay.value, startDay.value, {
            dateFormat: organization?.value?.date_format,
        });
    }
    return getDateRangeLabel(startDay.value, endDay.value, {
        dateFormat: organization?.value?.date_format,
    });
});

const nextRange = computed(() => {
    if (!startDay.value || !endDay.value) return undefined;
    return shiftDateRange(startDay.value, endDay.value, 1);
});

const nextDisabled = computed(
    () =>
        !props.allowFuture &&
        !!nextRange.value &&
        nextRange.value.start.isAfter(getLocalizedDayJs().endOf('day'))
);

function navigate(direction: DateRangeDirection) {
    if (!startDay.value || !endDay.value) return;
    const shifted = shiftDateRange(startDay.value, endDay.value, direction);
    emit('update:start', shifted.start.format());
    emit('update:end', shifted.end.format());
    emit('submit');
}

// Typed dates, so a custom range doesn't need clicking through months in the calendar.
const startText = ref('');
const endText = ref('');
const startInput = ref<HTMLInputElement | null>(null);
const endInput = ref<HTMLInputElement | null>(null);

function formatInputDate(day: ReturnType<typeof getLocalizedDayJs> | undefined) {
    return day ? formatDate(day.format('YYYY-MM-DD'), organization?.value?.date_format) : '';
}

function resetTexts() {
    startText.value = formatInputDate(startDay.value);
    endText.value = formatInputDate(endDay.value ?? startDay.value);
}

watch([startDay, endDay], resetTexts, { immediate: true });

// An untouched field keeps its date, so a preset ending in the future (This Month) can still
// have its start edited.
function fieldDate(
    text: string,
    day: ReturnType<typeof getLocalizedDayJs> | undefined
): string | null {
    if (day && text === formatInputDate(day)) return day.format('YYYY-MM-DD');
    const date = parseDateInput(text, organization?.value?.date_format);
    return date && (props.allowFuture || date <= today.value.toString()) ? date : null;
}

const typedStart = computed(() => fieldDate(startText.value, startDay.value));
const typedEnd = computed(() => fieldDate(endText.value, endDay.value ?? startDay.value));

const dateInputClass =
    'h-8 w-28 rounded-md border border-input-border bg-input-background px-2 text-sm tabular-nums text-text-primary focus:outline-none';

function applyTexts(edited: 'start' | 'end'): boolean {
    if (
        startText.value === formatInputDate(startDay.value) &&
        endText.value === formatInputDate(endDay.value ?? startDay.value)
    ) {
        return true;
    }
    let start = typedStart.value;
    let end = typedEnd.value;
    if (!start || !end) return false;
    // The edited date wins and the other one moves to meet it.
    if (start > end) {
        if (edited === 'start') end = start;
        else start = end;
    }
    emit('update:start', getLocalizedDayJsFromMinutes(start, 0).format());
    emit('update:end', getLocalizedDayJsFromMinutes(end, 0).endOf('day').format());
    return true;
}

function onTextBlur(edited: 'start' | 'end') {
    if (!applyTexts(edited)) resetTexts();
}

function onStartEnter() {
    if (applyTexts('start')) {
        endInput.value?.focus();
        endInput.value?.select();
    }
}

function onEndEnter() {
    if (applyTexts('end')) open.value = false;
}

function focusStartInput(event: Event) {
    event.preventDefault();
    startInput.value?.focus();
    startInput.value?.select();
}

watch(open, (value) => {
    if (value) {
        resetTexts();
    } else {
        emit('submit');
    }
});
</script>

<template>
    <DateRangeNavigator
        :label="displayLabel"
        :next-disabled="nextDisabled"
        previous-test-id="date_range_picker_previous"
        trigger-test-id="date_range_picker_display"
        next-test-id="date_range_picker_next"
        @previous="navigate(-1)"
        @next="navigate(1)">
        <template #trigger="{ triggerClass }">
            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        :class="twMerge(triggerClass, !modelValue.start && 'text-muted-foreground')"
                        data-testid="date_range_picker_display">
                        <CalendarIcon class="text-text-quaternary h-4 w-4 shrink-0" />
                        <span class="min-w-0 truncate">{{ displayLabel }}</span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-auto p-0" @open-auto-focus="focusStartInput">
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
                            <div class="flex items-center gap-2 px-3 pt-3">
                                <input
                                    ref="startInput"
                                    v-model="startText"
                                    type="text"
                                    aria-label="Start date"
                                    data-testid="date_range_picker_start_input"
                                    :class="
                                        twMerge(dateInputClass, !typedStart && 'border-red-500')
                                    "
                                    @keydown.enter.prevent="onStartEnter"
                                    @blur="onTextBlur('start')" />
                                <span class="text-text-tertiary">–</span>
                                <input
                                    ref="endInput"
                                    v-model="endText"
                                    type="text"
                                    aria-label="End date"
                                    data-testid="date_range_picker_end_input"
                                    :class="twMerge(dateInputClass, !typedEnd && 'border-red-500')"
                                    @keydown.enter.prevent="onEndEnter"
                                    @blur="onTextBlur('end')" />
                            </div>
                            <RangeCalendar
                                v-model="modelValue"
                                :number-of-months="2"
                                :max-value="allowFuture ? undefined : today"
                                :week-starts-on="weekStartsOn" />
                        </div>
                    </div>
                </PopoverContent>
            </Popover>
        </template>
    </DateRangeNavigator>
</template>
