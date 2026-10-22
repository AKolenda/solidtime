<script lang="ts" setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { EllipsisHorizontalIcon, StopIcon, TableCellsIcon } from '@heroicons/vue/20/solid';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/packages/ui/src';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';

// Running timers turn amber once they pass the "still running" e-mail threshold
const LONG_RUNNING_SECONDS = 8 * 60 * 60;

const props = defineProps<{
    name: string;
    memberId: string;
    description: string | null;
    projectId: string | null;
    projectName: string | null;
    projectColor: string | null;
    taskName: string | null;
    start: string;
    end: string | null;
    working: boolean;
    now: number;
    canStop: boolean;
}>();

const emit = defineEmits<{
    stop: [];
}>();

const reportHref = computed(() =>
    route('reporting.detailed', {
        member: props.memberId,
        project: props.projectId ?? undefined,
        range: 'all',
    })
);

const detail = computed(() => props.taskName ?? (props.description || null));

const runningSeconds = computed(() =>
    Math.max(0, Math.floor((props.now - getDayJsInstance()(props.start).valueOf()) / 1000))
);

const runningLabel = computed(() => {
    const hours = Math.floor(runningSeconds.value / 3600);
    const minutes = Math.floor((runningSeconds.value % 3600) / 60);
    return `${hours}h ${minutes.toString().padStart(2, '0')}m`;
});

const lastSeenLabel = computed(() => {
    const minutes = Math.max(
        0,
        Math.floor((props.now - getDayJsInstance()(props.end ?? props.start).valueOf()) / 60000)
    );
    if (minutes < 60) {
        return `${minutes}m ago`;
    }
    if (minutes < 24 * 60) {
        return `${Math.floor(minutes / 60)}h ago`;
    }
    return `${Math.floor(minutes / (24 * 60))}d ago`;
});
</script>

<template>
    <div
        class="group flex items-center gap-2 px-3.5 py-2 border-t border-card-background-separator first:border-t-0 hover:bg-card-background-active">
        <Link :href="reportHref" class="min-w-0 flex-1" data-testid="team_activity_entry">
            <p class="text-sm font-medium truncate text-text-primary">{{ name }}</p>
            <div class="flex items-center gap-1.5 text-sm text-text-secondary min-w-0">
                <span
                    v-if="projectName"
                    class="w-2 h-2 rounded-full shrink-0"
                    :style="{ backgroundColor: projectColor ?? undefined }"></span>
                <span class="truncate">{{ projectName ?? 'No project' }}</span>
            </div>
            <p
                v-if="detail"
                class="text-sm text-text-tertiary truncate"
                :class="{ 'pl-3.5': projectName }">
                {{ detail }}
            </p>
        </Link>
        <span
            v-if="working"
            class="flex items-center gap-1.5 text-sm tabular-nums shrink-0"
            :class="runningSeconds >= LONG_RUNNING_SECONDS ? 'text-amber-600' : 'text-green-500'"
            :title="runningSeconds >= LONG_RUNNING_SECONDS ? 'Running for a long time' : 'Working'">
            <span
                class="w-2 h-2 rounded-full"
                :class="
                    runningSeconds >= LONG_RUNNING_SECONDS ? 'bg-amber-500' : 'bg-green-500'
                "></span>
            {{ runningLabel }}
        </span>
        <span v-else class="text-sm text-text-tertiary shrink-0">{{ lastSeenLabel }}</span>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    class="shrink-0 rounded p-0.5 text-text-tertiary hover:text-text-primary hover:bg-card-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :aria-label="'Actions for ' + name">
                    <EllipsisHorizontalIcon class="w-5 h-5" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent class="min-w-[200px]" align="end">
                <DropdownMenuItem as-child class="flex items-center space-x-3 cursor-pointer">
                    <Link :href="reportHref">
                        <TableCellsIcon class="w-5 text-icon-active" />
                        <span>Open in Detailed report</span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="working && canStop"
                    :aria-label="'Stop timer of ' + name"
                    class="flex items-center space-x-3 cursor-pointer text-destructive focus:text-destructive"
                    @click="emit('stop')">
                    <StopIcon class="w-5" />
                    <span>Stop timer…</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
