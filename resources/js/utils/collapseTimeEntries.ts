import type { TimeEntry } from '@/packages/api/src';

/**
 * A time entry row that may stand in for several original, semantically identical entries.
 */
export type CollapsedTimeEntry = TimeEntry & {
    /** How many original entries this row represents (1 if it was not collapsed). */
    collapsed_count: number;
    /** Ids of all original entries represented by this row, including its own. */
    collapsed_ids: string[];
};

/**
 * Extra identity fields that are not part of the generated `TimeEntry` shape but may be passed
 * through by the API, so they are read defensively. They are a bonus, never the sole thing
 * separating two people — `user_id` is the field the API actually returns and is authoritative
 * for that. See `app/Http/Resources/V1/TimeEntry/TimeEntryResource.php`.
 */
const EXTRA_KEY_FIELDS = ['member_id', 'client_id'] as const;

function normalizeId(value: unknown): string | null {
    return value === null || value === undefined ? null : String(value);
}

/**
 * Compares two timestamps. Falls back to lexicographic ordering when a value is not parseable,
 * which keeps ISO 8601 strings ordered correctly either way.
 */
function compareTimestamps(a: string, b: string): number {
    const timeA = Date.parse(a);
    const timeB = Date.parse(b);
    if (Number.isNaN(timeA) || Number.isNaN(timeB)) {
        if (a === b) {
            return 0;
        }
        return a < b ? -1 : 1;
    }
    return timeA - timeB;
}

function groupKey(entry: TimeEntry): string {
    const record = entry as unknown as Record<string, unknown>;
    return JSON.stringify([
        normalizeId(entry.description),
        normalizeId(entry.project_id),
        normalizeId(entry.task_id),
        normalizeId(entry.user_id),
        ...EXTRA_KEY_FIELDS.map((field) => normalizeId(record[field])),
        entry.billable === true,
    ]);
}

type Accumulator = {
    base: TimeEntry;
    ids: string[];
    start: string;
    /** `null` as soon as any entry in the group is still running. */
    end: string | null;
    durationSum: number;
    hasDuration: boolean;
};

/**
 * Collapses semantically identical time entries (same description, project, task, user, member,
 * client and billable flag) into a single aggregated row.
 *
 * Entries logged by different users never collapse, so a Member column on the collapsed row
 * always attributes the work to the right person.
 *
 * - Durations are summed. A group whose entries all have a `null` duration keeps `null`.
 * - `start` becomes the earliest start, `end` the latest end — or `null` if any entry is running.
 * - The order of first appearance of each group is preserved; the input is never mutated.
 */
export function collapseTimeEntries(entries: TimeEntry[]): CollapsedTimeEntry[] {
    const groups = new Map<string, Accumulator>();

    for (const entry of entries) {
        const key = groupKey(entry);
        const duration = entry.duration;
        const end = entry.end ?? null;
        const existing = groups.get(key);

        if (existing === undefined) {
            groups.set(key, {
                base: entry,
                ids: [entry.id],
                start: entry.start,
                end,
                durationSum: typeof duration === 'number' ? duration : 0,
                hasDuration: typeof duration === 'number',
            });
            continue;
        }

        existing.ids.push(entry.id);

        if (compareTimestamps(entry.start, existing.start) < 0) {
            existing.start = entry.start;
        }

        if (end === null) {
            existing.end = null;
        } else if (existing.end !== null && compareTimestamps(end, existing.end) > 0) {
            existing.end = end;
        }

        if (typeof duration === 'number') {
            existing.durationSum += duration;
            existing.hasDuration = true;
        }
    }

    return Array.from(groups.values(), (group) => ({
        ...group.base,
        start: group.start,
        end: group.end,
        duration: group.hasDuration ? group.durationSum : null,
        collapsed_count: group.ids.length,
        collapsed_ids: [...group.ids],
    }));
}
