<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { PencilIcon } from '@heroicons/vue/16/solid';
import { Popover, PopoverContent, PopoverTrigger } from '@/packages/ui/src/popover';
import type { TimeEntry } from '@/packages/api/src';
import type { ReportEditableField, ReportEntryEditorContext } from './reportEntryEditing';
import DetailedReportFieldEditor from './DetailedReportFieldEditor.vue';

const props = defineProps<{
    field: ReportEditableField;
    entries: TimeEntry[];
    context: ReportEntryEditorContext;
}>();

const open = ref(false);
const saving = ref(false);
const loading = ref(false);
const error = ref('');
const editingEntries = ref<TimeEntry[]>([]);
const editorPanel = ref<HTMLElement | null>(null);
let loadSequence = 0;

function focusEditor(event?: Event) {
    // Focus inside the editor rather than the positioning wrapper, which lies outside
    // the popover's dismissable layer and would immediately dismiss it.
    event?.preventDefault();
    void nextTick(() => {
        const input = editorPanel.value?.querySelector<HTMLElement>('input, select, button');
        (input ?? editorPanel.value)?.focus();
    });
}
const label = computed(() => props.field.charAt(0).toUpperCase() + props.field.slice(1));
const editable = computed(() => {
    if (!props.entries.some(props.context.canEdit)) return false;
    if (props.field === 'member' && !props.context.canChangeMember) return false;
    if (['project', 'task', 'client', 'tags', 'billable'].includes(props.field)) {
        return props.entries.some(
            (entry) => entry.type !== 'break' && props.context.canEdit(entry)
        );
    }
    return true;
});

async function setOpen(value: boolean) {
    if (saving.value) return;
    const sequence = ++loadSequence;
    open.value = value;
    error.value = '';
    loading.value = false;
    if (value) {
        editingEntries.value = props.entries
            .filter(props.context.canEdit)
            .filter(
                (entry) =>
                    !['project', 'task', 'client', 'tags', 'billable'].includes(props.field) ||
                    entry.type !== 'break'
            )
            .map((entry) => ({ ...entry, tags: [...entry.tags] }));
        if (
            ['date', 'time', 'duration'].includes(props.field) &&
            props.context.loadOriginalEntries
        ) {
            loading.value = true;
            try {
                const entries = await props.context.loadOriginalEntries(
                    editingEntries.value.map((entry) => entry.id)
                );
                if (sequence === loadSequence) editingEntries.value = entries;
            } catch {
                if (sequence === loadSequence)
                    error.value = 'Could not load original times. Close this editor and try again.';
            } finally {
                if (sequence === loadSequence) {
                    loading.value = false;
                    if (open.value) focusEditor();
                }
            }
        }
    }
}
</script>

<template>
    <Popover v-if="editable" :open="open" @update:open="setOpen">
        <PopoverTrigger as-child>
            <button
                type="button"
                :aria-label="`Edit ${label.toLowerCase()}`"
                :title="`Edit ${label.toLowerCase()}`"
                :data-edit-field="field"
                class="group/edit flex min-h-11 w-full min-w-0 items-center gap-1 rounded px-1 -mx-1 text-start hover:bg-card-background-active focus-visible:outline focus-visible:outline-2 focus-visible:outline-input-select-active">
                <span class="flex flex-1 min-w-0 items-center gap-1.5"><slot /></span>
                <PencilIcon
                    class="size-3.5 shrink-0 text-icon-default opacity-0 group-hover/edit:opacity-100 group-focus-visible/edit:opacity-100" />
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            :collision-padding="12"
            class="max-w-[calc(100vw-24px)] max-h-[calc(100dvh-24px)] overflow-y-auto border-card-border bg-card-background text-text-primary shadow-dropdown"
            @open-auto-focus="focusEditor"
            @escape-key-down="saving && $event.preventDefault()"
            @interact-outside="saving && $event.preventDefault()">
            <div ref="editorPanel" tabindex="-1" class="outline-none">
                <p v-if="loading" class="p-4 text-sm" role="status">Loading original times…</p>
                <p v-else-if="error" class="p-4 text-sm text-red-600" role="alert">{{ error }}</p>
                <DetailedReportFieldEditor
                    v-else-if="open"
                    :field="field"
                    :entries="editingEntries"
                    :context="context"
                    @saving="saving = $event"
                    @close="open = false" />
            </div>
        </PopoverContent>
    </Popover>
    <div v-else class="flex min-h-11 min-w-0 items-center gap-1.5"><slot /></div>
</template>
