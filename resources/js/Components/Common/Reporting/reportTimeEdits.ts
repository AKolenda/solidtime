import type { Organization, TimeEntry } from '@/packages/api/src';
import { getDayJsInstance, getLocalizedDayJs, parseTimeInput } from '@/packages/ui/src/utils/time';
import { getUserTimezone } from '@/packages/ui/src/utils/settings';

type EntryTimes = Pick<TimeEntry, 'start' | 'end'>;

const LOCAL_DATE = /^\d{4}-\d{2}-\d{2}$/;
const LOCAL_DATE_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/;

function utcTimestamp(value: ReturnType<typeof getLocalizedDayJs>): string {
    return value.utc().format('YYYY-MM-DDTHH:mm:ss[Z]');
}

function parseLocalDateTime(value: string, label: string) {
    if (!LOCAL_DATE_TIME.test(value)) {
        throw new Error(`${label} must be a valid local date and time`);
    }

    const parsed = getDayJsInstance().tz(value, getUserTimezone());
    const format = value.length === 16 ? 'YYYY-MM-DDTHH:mm' : 'YYYY-MM-DDTHH:mm:ss';
    if (!parsed.isValid() || parsed.format(format) !== value) {
        throw new Error(`${label} must be a valid local date and time`);
    }
    return parsed;
}

export function changeEntryDate(entry: TimeEntry, localDate: string): EntryTimes {
    if (!LOCAL_DATE.test(localDate)) {
        throw new Error('Date must use YYYY-MM-DD');
    }

    const originalStart = getLocalizedDayJs(entry.start);
    const localStart = parseLocalDateTime(
        `${localDate}T${originalStart.format('HH:mm:ss')}`,
        'Start time'
    );
    const durationSeconds = entry.end
        ? getDayJsInstance().utc(entry.end).diff(getDayJsInstance().utc(entry.start), 'second')
        : null;

    return {
        start: utcTimestamp(localStart),
        end:
            durationSeconds === null
                ? null
                : utcTimestamp(localStart.add(durationSeconds, 'second')),
    };
}

export function changeEntryTimes(
    entry: TimeEntry,
    localStart: string,
    localEnd: string | null
): EntryTimes {
    const start = parseLocalDateTime(localStart, 'Start time');

    if (entry.end === null) {
        return { start: utcTimestamp(start), end: null };
    }
    if (localEnd === null) {
        throw new Error('End time is required for a finished entry');
    }

    const end = parseLocalDateTime(localEnd, 'End time');
    if (end.isBefore(start)) {
        throw new Error('End time must not be before start time');
    }

    return { start: utcTimestamp(start), end: utcTimestamp(end) };
}

export function changeEntryDuration(
    entry: TimeEntry,
    text: string,
    organization?: Organization
): EntryTimes {
    if (entry.end === null) {
        throw new Error('Duration cannot be changed while the entry is running');
    }

    const seconds = parseTimeInput(
        text.trim(),
        organization?.number_format,
        organization?.interval_format === 'decimal' ? 'hours' : 'minutes'
    );
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
        throw new Error('Duration must be greater than zero');
    }

    const start = getDayJsInstance().utc(entry.start);
    if (!start.isValid()) {
        throw new Error('Entry start time is invalid');
    }
    return { start: utcTimestamp(start), end: utcTimestamp(start.add(seconds, 'second')) };
}
