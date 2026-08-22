<script setup lang="ts">
import { computed } from 'vue';
import TableRow from '@/Components/TableRow.vue';
import {
    Checkbox,
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuSeparator,
    ContextMenuTrigger,
} from '@/packages/ui/src';
import TimeEntryMoreOptionsDropdown from '@/packages/ui/src/TimeEntry/TimeEntryMoreOptionsDropdown.vue';
import BillableIcon from '@/packages/ui/src/Icons/BillableIcon.vue';
import TagBadge from '@/packages/ui/src/Tag/TagBadge.vue';
import BreakLabel from '@/packages/ui/src/TimeEntry/BreakLabel.vue';
import { PlayIcon, PencilIcon, DocumentDuplicateIcon, TrashIcon } from '@heroicons/vue/20/solid';
import {
    formatDateLocalized,
    formatHumanReadableDuration,
    formatStartEnd,
    getLocalizedDayJs,
} from '@/packages/ui/src/utils/time';
import type { Client, Member, Organization, Project, Tag, Task } from '@/packages/api/src';
import type { DetailedReportRow } from '@/Components/Common/Reporting/DetailedReportTable.vue';

const props = defineProps<{
    entry: DetailedReportRow;
    selected: boolean;
    canRecreate: boolean;
    projects: Map<string, Project>;
    tasks: Map<string, Task>;
    clients: Map<string, Client>;
    tags: Map<string, Tag>;
    /** Keyed by `user_id`, because a time entry references the user, not the membership. */
    members: Map<string, Member>;
    organization: Organization | undefined;
}>();

const emit = defineEmits<{
    selected: [];
    unselected: [];
    edit: [];
    duplicate: [];
    delete: [];
    start: [];
}>();

const isBreak = computed(() => props.entry.type === 'break');

const project = computed(() =>
    props.entry.project_id ? props.projects.get(props.entry.project_id) : undefined
);

const task = computed(() =>
    props.entry.task_id ? props.tasks.get(props.entry.task_id) : undefined
);

// Time entries carry no client of their own — it comes from the project they belong to.
const client = computed(() =>
    project.value?.client_id ? props.clients.get(project.value.client_id) : undefined
);

const memberName = computed(() => props.members.get(props.entry.user_id)?.name ?? '');

const entryTags = computed(() =>
    props.entry.tags
        .map((tagId) => props.tags.get(tagId))
        .filter((tag): tag is Tag => tag !== undefined)
);

const durationSeconds = computed<number | null>(() => {
    if (props.entry.duration !== null && props.entry.duration !== undefined) {
        return props.entry.duration;
    }
    if (!props.entry.end) {
        return null;
    }
    return getLocalizedDayJs(props.entry.end).diff(getLocalizedDayJs(props.entry.start), 's');
});

const durationLabel = computed(() => {
    if (durationSeconds.value === null) {
        return '--';
    }
    return formatHumanReadableDuration(
        durationSeconds.value,
        props.organization?.interval_format,
        props.organization?.number_format
    );
});

const firstTag = computed<Tag | undefined>(() => entryTags.value[0]);

function onSelectChange(checked: boolean | unknown[]) {
    if (checked === true) {
        emit('selected');
    } else {
        emit('unselected');
    }
}
</script>

<template>
    <ContextMenu>
        <ContextMenuTrigger as-child>
            <!--
                Cell order has to stay in sync with the column list in DetailedReportTable.vue,
                the grid template positions these by order, not by name.
            -->
            <TableRow>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 pl-4 sm:pl-6 lg:pl-8">
                    <Checkbox
                        :checked="selected"
                        aria-label="Select time entry"
                        @update:checked="onSelectChange" />
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-secondary">
                    <span class="truncate">{{
                        formatDateLocalized(entry.start, organization?.date_format)
                    }}</span>
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <span v-if="memberName" class="truncate">{{ memberName }}</span>
                    <span v-else class="text-text-tertiary">--</span>
                </div>
                <div
                    class="flex items-center gap-2 min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <BreakLabel v-if="isBreak" class="shrink-0" />
                    <span v-if="entry.description" class="truncate">{{ entry.description }}</span>
                    <span v-else-if="!isBreak" class="text-text-tertiary">No description</span>
                    <span
                        v-if="entry.collapsed_count > 1"
                        :title="`${entry.collapsed_count} identical entries grouped`"
                        class="shrink-0 rounded-full bg-secondary px-1.5 py-0.5 text-xs font-medium text-text-secondary">
                        x{{ entry.collapsed_count }}
                    </span>
                </div>
                <div
                    class="flex items-center gap-2 min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <template v-if="project">
                        <div
                            :style="{ backgroundColor: project.color }"
                            class="w-2.5 h-2.5 shrink-0 rounded-full"></div>
                        <span class="truncate">{{ project.name }}</span>
                    </template>
                    <span v-else class="text-text-tertiary">No project</span>
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <span v-if="task" class="truncate">{{ task.name }}</span>
                    <span v-else class="text-text-tertiary">--</span>
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <span v-if="client" class="truncate">{{ client.name }}</span>
                    <span v-else class="text-text-tertiary">No client</span>
                </div>
                <div
                    class="flex items-center gap-1 min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-primary">
                    <TagBadge
                        v-if="firstTag"
                        :name="firstTag.name"
                        :border="false"
                        class="min-w-0"></TagBadge>
                    <span v-if="entryTags.length > 1" class="shrink-0 text-xs text-text-tertiary">
                        +{{ entryTags.length - 1 }}
                    </span>
                    <span v-if="entryTags.length === 0" class="text-text-tertiary">--</span>
                </div>
                <div class="flex items-center min-w-0 overflow-hidden px-3 py-2.5">
                    <BillableIcon
                        :aria-label="entry.billable ? 'Billable' : 'Non billable'"
                        class="w-5 h-5"
                        :class="
                            entry.billable ? 'text-input-select-active' : 'text-icon-default/40'
                        "></BillableIcon>
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm text-text-secondary">
                    <span class="truncate">{{
                        formatStartEnd(entry.start, entry.end, organization?.time_format)
                    }}</span>
                </div>
                <div
                    class="flex items-center min-w-0 overflow-hidden px-3 py-2.5 text-sm font-medium text-text-primary">
                    <span class="truncate">{{ durationLabel }}</span>
                </div>
                <div class="flex items-center justify-end min-w-0 overflow-hidden px-1 py-2.5">
                    <TimeEntryMoreOptionsDropdown
                        :show-duplicate="canRecreate"
                        @edit="emit('edit')"
                        @duplicate="emit('duplicate')"
                        @delete="emit('delete')"></TimeEntryMoreOptionsDropdown>
                </div>
                <!-- Filler cell so the row background spans any leftover width. -->
                <div></div>
            </TableRow>
        </ContextMenuTrigger>
        <ContextMenuContent class="min-w-[160px]">
            <ContextMenuItem v-if="canRecreate" class="space-x-3" @select="emit('start')">
                <PlayIcon class="w-4 h-4 text-icon-default" />
                <span>Continue</span>
            </ContextMenuItem>
            <ContextMenuItem class="space-x-3" @select="emit('edit')">
                <PencilIcon class="w-4 h-4 text-icon-default" />
                <span>Edit</span>
            </ContextMenuItem>
            <ContextMenuItem v-if="canRecreate" class="space-x-3" @select="emit('duplicate')">
                <DocumentDuplicateIcon class="w-4 h-4 text-icon-default" />
                <span>Duplicate</span>
            </ContextMenuItem>
            <ContextMenuSeparator />
            <ContextMenuItem class="space-x-3 text-destructive" @select="emit('delete')">
                <TrashIcon class="w-4 h-4 text-icon-default" />
                <span>Delete</span>
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>

<style scoped></style>
