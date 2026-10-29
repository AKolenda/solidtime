import type { Task } from '@/packages/api/src';

export interface TaskFilterOption {
    key: string;
    name: string;
    taskIds: string[];
    // latest time entry start across the tasks with this name
    lastActivityAt: string | null;
    // every task with this name is done
    isDone: boolean;
}

function taskNameKey(name: string): string {
    return `task:${name.trim().toLocaleLowerCase()}`;
}

export function buildTaskFilterOptions(tasks: Task[], projectIds: string[]): TaskFilterOption[] {
    const selectedProjects = new Set(projectIds);
    const optionsByName = new Map<string, TaskFilterOption>();

    for (const task of tasks) {
        if (selectedProjects.size > 0 && !selectedProjects.has(task.project_id)) {
            continue;
        }

        const key = taskNameKey(task.name);
        const existing = optionsByName.get(key);

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
            optionsByName.set(key, {
                key,
                name: task.name.trim(),
                taskIds: [task.id],
                lastActivityAt,
                isDone: task.is_done,
            });
        }
    }

    return [...optionsByName.values()].sort((a, b) => a.name.localeCompare(b.name));
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
