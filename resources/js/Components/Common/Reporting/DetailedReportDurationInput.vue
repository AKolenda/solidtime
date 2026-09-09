<script setup lang="ts">
import { nextTick, ref } from 'vue';
import type { TimeEntry } from '@/packages/api/src';
import type { ReportEntryEditorContext } from './reportEntryEditing';
import { changeEntryDuration } from './reportTimeEdits';
import { formatHumanReadableDuration, getLocalizedDayJs } from '@/packages/ui/src/utils/time';

const props = defineProps<{ entries: TimeEntry[]; context: ReportEntryEditorContext }>();
const editing = ref(false);
const busy = ref(false);
const error = ref('');
const input = ref<HTMLInputElement>();
const container = ref<HTMLElement>();
const originals = ref<TimeEntry[]>([]);
const selectedId = ref('');
const draft = ref('');
let initial = '';
let sequence = 0;

function resetDraft() {
    const entry = originals.value.find((entry) => entry.id === selectedId.value);
    if (!entry?.end) return;
    const seconds = Math.max(
        0,
        Math.round((Date.parse(entry.end) - Date.parse(entry.start)) / 1000)
    );
    draft.value = formatHumanReadableDuration(
        seconds,
        props.context.organization?.interval_format,
        props.context.organization?.number_format
    );
    initial = draft.value;
    error.value = '';
    void nextTick(() => {
        input.value?.focus();
        input.value?.select();
    });
}

async function begin() {
    const request = ++sequence;
    editing.value = true;
    busy.value = true;
    error.value = '';
    try {
        const entries = props.entries.filter((entry) => props.context.canEdit(entry) && entry.end);
        const loaded = props.context.loadOriginalEntries
            ? await props.context.loadOriginalEntries(entries.map((entry) => entry.id))
            : entries;
        if (request !== sequence) return;
        originals.value = loaded.filter(
            (entry) => entries.some((original) => original.id === entry.id) && entry.end
        );
        if (originals.value.length !== entries.length || !originals.value.length) throw new Error();
        selectedId.value = originals.value[0]!.id;
        resetDraft();
    } catch {
        if (request === sequence) error.value = 'Could not load original times. Click to retry.';
    } finally {
        if (request === sequence) busy.value = false;
    }
}

function cancel() {
    if (busy.value) return;
    ++sequence;
    editing.value = false;
    error.value = '';
}

function handleBlur(event: FocusEvent) {
    if (event.relatedTarget instanceof Node && container.value?.contains(event.relatedTarget))
        return;
    void save();
}

async function save() {
    if (busy.value || !editing.value || !originals.value.length) return;
    if (draft.value === initial) {
        cancel();
        return;
    }
    try {
        const entry = originals.value.find((entry) => entry.id === selectedId.value)!;
        const changes = changeEntryDuration(entry, draft.value, props.context.organization);
        busy.value = true;
        await props.context.update([entry.id], changes);
        editing.value = false;
        error.value = '';
    } catch (cause) {
        error.value =
            cause instanceof Error && cause.message !== 'Failed to handle API request'
                ? cause.message
                : 'Could not save duration. Try again.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div ref="container" class="w-full min-w-0 flex-1" @keydown.esc.prevent.stop="cancel">
        <template v-if="editing">
            <input
                v-if="originals.length"
                ref="input"
                v-model="draft"
                type="text"
                aria-label="Duration"
                placeholder="hh:mm:ss"
                :disabled="busy"
                :aria-invalid="Boolean(error)"
                class="duration-control bg-input-background border-input-border focus:outline-none focus:ring-2 focus:ring-inset focus:ring-input-select-active"
                @keydown.enter.prevent="save"
                @blur="handleBlur" />
            <span v-else-if="busy" role="status" class="text-xs">Loading…</span>
            <select
                v-if="originals.length > 1"
                v-model="selectedId"
                aria-label="Time entry to edit"
                class="w-full min-h-11 rounded border border-input-border bg-input-background text-xs"
                :disabled="busy || draft !== initial"
                @change="resetDraft">
                <option v-for="entry in originals" :key="entry.id" :value="entry.id">
                    {{ getLocalizedDayJs(entry.start).format('YYYY-MM-DD HH:mm') }}
                </option>
            </select>

            <button
                v-if="error && !originals.length"
                type="button"
                class="text-xs underline"
                @click="begin">
                Retry
            </button>
            <p v-if="error" role="alert" class="text-xs text-red-600">{{ error }}</p>
        </template>
        <button
            v-else
            type="button"
            data-edit-field="duration"
            aria-label="Edit duration"
            class="duration-control flex items-center border-transparent hover:bg-card-background-active focus-visible:outline focus-visible:outline-2 focus-visible:outline-input-select-active"
            @click="begin">
            <slot />
        </button>
    </div>
</template>

<style scoped>
.duration-control {
    @apply box-border h-11 w-full min-w-0 rounded border px-1 py-0 text-left text-sm font-medium leading-5;
}
</style>
