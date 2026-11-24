import type { Task } from '@/packages/api/src';

export interface TaskFilterOption {
    key: string;
    name: string;
    taskIds: string[];
    // latest time entry start across the tasks in this option
    lastActivityAt: string | null;
    // every task in this option is done
    isDone: boolean;
    // a name no new task has been given lately, kept only for old projects
    isLegacy: boolean;
}

// A name that no task was created with this long before the newest task is no longer in use.
const LEGACY_AFTER_MS = 30 * 24 * 60 * 60 * 1000;

// The shop's standard tasks have been named many ways over time ("Turning Run", "Turning - Run",
// "Turning - Running"), so tasks are grouped by operation and stage rather than by exact name.
function taskGroupKey(name: string): string | null {
    const normalized = name.trim().toLocaleLowerCase();
    const operation = /^(turn|t\b)/.test(normalized)
        ? 'turning'
        : /^(mill|m\b)/.test(normalized)
          ? 'milling'
          : null;
    if (!operation) {
        return null;
    }
    const rest = normalized.replace(/^\S+/, '');
    const stage = /\brun/.test(rest)
        ? 'running'
        : /program|set-?up|f\/o|first.?off/.test(rest)
          ? 'setup'
          : null;

    return stage ? `task-group:${operation}:${stage}` : null;
}

function taskOptionKey(name: string): string {
    return taskGroupKey(name) ?? `task:${name.trim().toLocaleLowerCase()}`;
}

interface NameUsage {
    name: string;
    count: number;
    newestCreatedAt: string;
}

// Each option is named after the name most recently given to a new task, so old spellings
// are listed under the one in use now.
function currentNames(tasks: Task[]): Map<string, NameUsage> {
    const usageByName = new Map<string, NameUsage>();
    for (const task of tasks) {
        const name = task.name.trim();
        const usage = usageByName.get(name.toLocaleLowerCase()) ?? {
            name,
            count: 0,
            newestCreatedAt: '',
        };
        usage.count++;
        if ((task.created_at ?? '') > usage.newestCreatedAt) {
            usage.newestCreatedAt = task.created_at;
        }
        usageByName.set(name.toLocaleLowerCase(), usage);
    }

    const currentByKey = new Map<string, NameUsage>();
    for (const usage of usageByName.values()) {
        const key = taskOptionKey(usage.name);
        const current = currentByKey.get(key);
        if (
            !current ||
            usage.newestCreatedAt > current.newestCreatedAt ||
            (usage.newestCreatedAt === current.newestCreatedAt && usage.count > current.count)
        ) {
            currentByKey.set(key, usage);
        }
    }

    return currentByKey;
}

export function buildTaskFilterOptions(tasks: Task[], projectIds: string[]): TaskFilterOption[] {
    const selectedProjects = new Set(projectIds);
    const namesByKey = currentNames(tasks);
    const newestCreatedAt = tasks.reduce(
        (newest, task) => ((task.created_at ?? '') > newest ? task.created_at : newest),
        ''
    );
    const legacyBefore = newestCreatedAt
        ? new Date(new Date(newestCreatedAt).getTime() - LEGACY_AFTER_MS).toISOString()
        : '';
    const optionsByKey = new Map<string, TaskFilterOption>();

    for (const task of tasks) {
        if (selectedProjects.size > 0 && !selectedProjects.has(task.project_id)) {
            continue;
        }

        const key = taskOptionKey(task.name);
        const existing = optionsByKey.get(key);

        const lastActivityAt = task.last_activity_at ?? null;

        if (existing) {
            existing.taskIds.push(task.id);
            existing.isDone = existing.isDone && task.is_done;
            if (
                lastActivityAt &&
                (!existing.lastActivityAt || lastActivityAt > existing.lastActivityAt)
            ) {
                existing.lastActivityAt = lastActivityAt;
            }
        } else {
            const current = namesByKey.get(key);
            optionsByKey.set(key, {
                key,
                name: current?.name ?? task.name.trim(),
                taskIds: [task.id],
                lastActivityAt,
                isDone: task.is_done,
                isLegacy:
                    !taskGroupKey(task.name) &&
                    !!current?.newestCreatedAt &&
                    current.newestCreatedAt < legacyBefore,
            });
        }
    }

    return [...optionsByKey.values()].sort((a, b) => a.name.localeCompare(b.name));
}

export function getSelectedTaskOptionKeys(
    options: TaskFilterOption[],
    selectedTaskIds: string[]
): string[] {
    const selectedIds = new Set(selectedTaskIds);

    return options
        .filter((option) => option.taskIds.some((taskId) => selectedIds.has(taskId)))
        .map((option) => option.key);
}

export function getTaskIdsForOptionKeys(
    options: TaskFilterOption[],
    selectedOptionKeys: string[]
): string[] {
    const selectedKeys = new Set(selectedOptionKeys);

    return options
        .filter((option) => selectedKeys.has(option.key))
        .flatMap((option) => option.taskIds);
}
