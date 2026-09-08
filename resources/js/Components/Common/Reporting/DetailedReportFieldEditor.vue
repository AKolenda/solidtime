<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useMediaQuery } from '@vueuse/core';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import {
    ComboboxRoot,
    ComboboxAnchor,
    ComboboxInput,
    ComboboxContent,
    ComboboxViewport,
    ComboboxVirtualizer,
    ComboboxItem,
} from 'reka-ui';
import {
    projectPickerStorageKey,
    useResizableDropdown,
} from '@/packages/ui/src/Input/useResizableDropdown';
import { formatHumanReadableDuration, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import { changeEntryDate, changeEntryTimes, changeEntryDuration } from './reportTimeEdits';
import type { TimeEntry } from '@/packages/api/src';
import type {
    ReportEditableField,
    ReportEntryChanges,
    ReportEntryEditorContext,
} from './reportEntryEditing';

const props = defineProps<{
    field: ReportEditableField;
    entries: TimeEntry[];
    context: ReportEntryEditorContext;
}>();
const emit = defineEmits<{ close: []; saving: [value: boolean] }>();
const compactRows = useMediaQuery('(min-width: 640px)');
const selectedId = ref(props.entries[0]?.id ?? '');
const selectedEntry = computed(
    () => props.entries.find((entry) => entry.id === selectedId.value) ?? props.entries[0]!
);
const targetEntries = computed(() =>
    selectedId.value === 'all' ? props.entries : [selectedEntry.value]
);
const temporal = computed(() => ['date', 'time', 'duration'].includes(props.field));
const label = computed(() => props.field.charAt(0).toUpperCase() + props.field.slice(1));
const search = ref('');
const selectedClient = ref<string | null>(null);
const choosingClient = computed(() => props.field === 'client' && selectedClient.value === null);
const searchLabel = computed(() =>
    choosingClient.value ? 'clients' : projectPicker.value ? 'projects' : label.value.toLowerCase()
);
const text = ref('');
const start = ref('');
const end = ref('');
const selectedTags = ref<string[]>([]);
const saving = ref(false);
const error = ref('');
const initialDraft = ref('');
const draft = computed(() =>
    JSON.stringify([text.value, start.value, end.value, selectedTags.value])
);
const dirty = computed(() => draft.value !== initialDraft.value);

watch(
    selectedEntry,
    (entry) => {
        text.value =
            props.field === 'date'
                ? getLocalizedDayJs(entry.start).format('YYYY-MM-DD')
                : props.field === 'duration'
                  ? formatHumanReadableDuration(
                        entry.end
                            ? getLocalizedDayJs(entry.end).diff(
                                  getLocalizedDayJs(entry.start),
                                  'second'
                              )
                            : 0,
                        props.context.organization?.interval_format,
                        props.context.organization?.number_format
                    )
                  : (entry.description ?? '');
        start.value = getLocalizedDayJs(entry.start).format('YYYY-MM-DDTHH:mm:ss');
        end.value = entry.end ? getLocalizedDayJs(entry.end).format('YYYY-MM-DDTHH:mm:ss') : '';
        selectedTags.value = [...entry.tags];
        initialDraft.value = draft.value;
        error.value = '';
    },
    { immediate: true }
);

function timeLabel(entry: TimeEntry) {
    const start = getLocalizedDayJs(entry.start);
    const end = entry.end ? getLocalizedDayJs(entry.end) : null;
    return `${start.format('YYYY-MM-DD HH:mm')} – ${end ? end.format(end.isSame(start, 'day') ? 'HH:mm' : 'YYYY-MM-DD HH:mm') : 'Running'}`;
}

type Option = { id: string; name: string; detail?: string };
const projectPicker = computed(() => props.field === 'project' || props.field === 'client');
const listField = computed(() =>
    ['project', 'client', 'task', 'member', 'tags', 'billable'].includes(props.field)
);
const clientsById = computed(
    () => new Map(props.context.clients.map((client) => [client.id, client.name]))
);
const options = computed<Option[]>(() => {
    if (choosingClient.value) {
        return [
            { id: '', name: 'No client' },
            ...props.context.clients.map((client) => ({ id: client.id, name: client.name })),
        ];
    }
    if (projectPicker.value) {
        return [
            ...(props.field !== 'client' || selectedClient.value === ''
                ? [{ id: '', name: 'No project' }]
                : []),
            ...props.context.projects
                .filter(
                    (project) =>
                        props.field !== 'client' ||
                        (project.client_id ?? '') === selectedClient.value
                )
                .map((project) => ({
                    id: project.id,
                    name: project.name,
                    detail: `${clientsById.value.get(project.client_id ?? '') ?? 'No client'}${project.is_archived ? ' · Archived' : ''}`,
                })),
        ];
    }
    if (props.field === 'task') {
        return [
            { id: '', name: 'No task' },
            ...props.context.tasks
                .filter((task) => task.project_id === selectedEntry.value.project_id)
                .map((task) => ({
                    id: task.id,
                    name: task.name,
                    detail: task.is_done ? 'Completed' : undefined,
                })),
        ];
    }
    if (props.field === 'member')
        return props.context.members.map((member) => ({ id: member.id, name: member.name }));
    if (props.field === 'tags')
        return props.context.tags.map((tag) => ({ id: tag.id, name: tag.name }));
    return [
        { id: 'true', name: 'Billable' },
        { id: 'false', name: 'Non-billable' },
    ];
});
const filteredOptions = computed(() => {
    const query = search.value.trim().toLowerCase();
    return options.value.filter((option) =>
        `${option.name} ${option.detail ?? ''}`.toLowerCase().includes(query)
    );
});
function isSelected(id: string) {
    const entry = selectedEntry.value;
    if (choosingClient.value)
        return (
            (props.context.projects.find((project) => project.id === entry.project_id)?.client_id ??
                '') === id
        );
    if (projectPicker.value) return (entry.project_id ?? '') === id;
    if (props.field === 'task') return (entry.task_id ?? '') === id;
    if (props.field === 'member')
        return props.context.members.find((member) => member.user_id === entry.user_id)?.id === id;
    if (props.field === 'tags') return selectedTags.value.includes(id);
    return String(entry.billable) === id;
}

async function save(changes: ReportEntryChanges) {
    if (saving.value) return;
    saving.value = true;
    emit('saving', true);
    error.value = '';
    try {
        await props.context.update(
            targetEntries.value.map((entry) => entry.id),
            changes
        );
        emit('close');
    } catch (cause) {
        error.value =
            cause instanceof Error && cause.message !== 'Failed to handle API request'
                ? cause.message
                : 'Could not save this change. Please try again.';
    } finally {
        saving.value = false;
        emit('saving', false);
    }
}

function chooseOption(id: string) {
    if (saving.value) return;
    if (choosingClient.value) {
        selectedClient.value = id;
        search.value = '';
        return;
    }
    if (props.field === 'tags') {
        selectedTags.value = selectedTags.value.includes(id)
            ? selectedTags.value.filter((tag) => tag !== id)
            : [...selectedTags.value, id];
        return;
    }
    if (projectPicker.value) {
        // Client follows the selected project. A task from the previous project cannot follow it.
        void save({
            project_id: id || null,
            task_id: id === selectedEntry.value.project_id ? selectedEntry.value.task_id : null,
        });
    } else if (props.field === 'task') {
        void save({ project_id: selectedEntry.value.project_id, task_id: id || null });
    } else if (props.field === 'member') {
        void save({ member_id: id });
    } else {
        void save({ billable: id === 'true' });
    }
}

function submit() {
    try {
        const entry = selectedEntry.value;
        if (props.field === 'description') void save({ description: text.value });
        else if (props.field === 'tags') void save({ tags: selectedTags.value });
        else if (props.field === 'date') void save(changeEntryDate(entry, text.value));
        else if (props.field === 'time')
            void save(changeEntryTimes(entry, start.value, entry.end ? end.value : null));
        else if (props.field === 'duration')
            void save(changeEntryDuration(entry, text.value, props.context.organization));
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Enter a valid time.';
    }
}

const { setResizablePanel, resizablePanelStyle, resizeHandleProps } = useResizableDropdown(
    projectPickerStorageKey(props.context.userId)
);
</script>

<template>
    <ComboboxRoot as-child :open="listField" :ignore-filter="true">
        <form
            class="p-2 space-y-2 w-max max-w-full"
            :aria-label="`Edit ${label.toLowerCase()}`"
            :aria-busy="saving"
            @submit.prevent="submit">
            <div v-if="entries.length > 1" class="space-y-1">
                <label class="text-xs text-text-secondary block">
                    Time entry ({{ entries.length }} grouped)
                    <select
                        v-model="selectedId"
                        class="report-edit-input mt-1"
                        :disabled="dirty || saving">
                        <option v-if="!temporal" value="all">
                            All {{ entries.length }} entries
                        </option>
                        <option v-for="entry in entries" :key="entry.id" :value="entry.id">
                            {{ timeLabel(entry) }}
                        </option>
                    </select>
                </label>
                <p v-if="dirty" class="text-xs text-text-secondary">
                    Save or cancel before choosing another entry.
                </p>
            </div>
            <button
                v-if="field === 'client' && !choosingClient"
                type="button"
                class="flex min-h-11 sm:min-h-8 items-center gap-2 px-2 text-sm text-text-secondary hover:text-text-primary"
                :disabled="saving"
                @click="
                    selectedClient = null;
                    search = '';
                ">
                ? {{ clientsById.get(selectedClient ?? '') ?? 'No client' }} ? Choose project
            </button>
            <template v-if="listField">
                <div class="space-y-2">
                    <ComboboxAnchor v-if="field !== 'billable'">
                        <ComboboxInput
                            v-model="search"
                            :aria-label="`Search ${searchLabel}`"
                            :placeholder="`Search ${searchLabel}…`"
                            class="report-edit-input"
                            :disabled="saving" />
                    </ComboboxAnchor>
                    <ComboboxContent position="inline" :dismiss-able="false">
                        <ComboboxViewport
                            :ref="setResizablePanel"
                            :style="resizablePanelStyle"
                            class="w-80 max-w-[calc(100vw-40px)] max-h-60 overflow-y-auto">
                            <ComboboxVirtualizer
                                v-slot="{ option }"
                                :options="filteredOptions"
                                :estimate-size="compactRows ? 32 : 44"
                                :text-content="(option: Option) => option.name">
                                <ComboboxItem
                                    :value="option.id || '__none__'"
                                    :disabled="saving"
                                    class="flex w-full min-h-11 sm:min-h-8 items-center gap-2 rounded-md px-2 py-1.5 text-sm text-text-primary cursor-default data-[highlighted]:bg-card-background-active"
                                    @select.prevent="chooseOption(option.id)">
                                    <Checkbox
                                        :checked="isSelected(option.id)"
                                        aria-hidden="true"
                                        :tabindex="-1"
                                        class="pointer-events-none shrink-0" />
                                    <span
                                        class="min-w-0 truncate"
                                        :data-option-id="option.id"
                                        :title="
                                            option.detail
                                                ? `${option.name} ? ${option.detail}`
                                                : option.name
                                        "
                                        >{{ option.name }}</span
                                    >
                                </ComboboxItem>
                            </ComboboxVirtualizer>
                            <p
                                v-if="filteredOptions.length === 0"
                                class="p-3 text-sm text-text-secondary">
                                {{
                                    field === 'client' && !choosingClient
                                        ? 'No projects for this client'
                                        : 'No matches'
                                }}
                            </p>
                        </ComboboxViewport>
                    </ComboboxContent>
                </div>
            </template>
            <template v-else-if="field === 'time'">
                <label class="block text-sm"
                    >Start<input
                        v-model="start"
                        aria-label="Start"
                        type="datetime-local"
                        step="1"
                        class="report-edit-input mt-1"
                        :disabled="saving"
                        required
                /></label>
                <label v-if="selectedEntry.end" class="block text-sm"
                    >End<input
                        v-model="end"
                        aria-label="End"
                        type="datetime-local"
                        step="1"
                        class="report-edit-input mt-1"
                        :disabled="saving"
                        required
                /></label>
                <p v-else class="text-xs text-text-secondary">This entry is still running.</p>
            </template>
            <label v-else class="block text-sm">
                {{ label }}
                <input
                    v-model="text"
                    :aria-label="label"
                    :type="field === 'date' ? 'date' : 'text'"
                    :maxlength="field === 'description' ? 5000 : undefined"
                    :placeholder="field === 'duration' ? '2h 30m' : undefined"
                    class="report-edit-input mt-1 w-80 max-w-full"
                    :disabled="saving"
                    :required="field !== 'description'" />
            </label>
            <p v-if="error" role="alert" class="text-sm text-red-600 max-w-sm">{{ error }}</p>
            <div class="flex items-center justify-between gap-2">
                <div v-if="!listField || field === 'tags'" class="ml-auto flex gap-2">
                    <button
                        type="button"
                        class="min-h-11 px-3 rounded hover:bg-card-background-active text-sm"
                        :disabled="saving"
                        @click="emit('close')">
                        Cancel
                    </button>
                    <button
                        v-if="!listField || field === 'tags'"
                        type="submit"
                        class="min-h-11 px-3 rounded bg-input-select-active text-white text-sm"
                        :disabled="saving">
                        {{
                            saving
                                ? 'Saving…'
                                : selectedId === 'all'
                                  ? `Save ${entries.length} entries`
                                  : 'Save'
                        }}
                    </button>
                </div>
                <button v-if="listField" type="button" v-bind="resizeHandleProps"></button>
            </div>
        </form>
    </ComboboxRoot>
</template>

<style scoped>
.report-edit-input {
    @apply block w-full min-h-11 sm:min-h-8 rounded-md border border-input-border bg-input-background px-3 text-sm text-text-primary placeholder:text-text-tertiary focus:outline-none focus:ring-2 focus:ring-input-select-active disabled:opacity-60;
}
</style>
