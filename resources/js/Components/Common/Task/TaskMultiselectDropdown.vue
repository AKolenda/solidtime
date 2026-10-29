<script setup lang="ts">
import MultiselectDropdown from '@/packages/ui/src/Input/MultiselectDropdown.vue';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { computed, watch } from 'vue';
import {
    buildTaskFilterOptions,
    getSelectedTaskOptionKeys,
    getTaskIdsForOptionKeys,
    type TaskFilterOption,
} from './taskFilterOptions';

const NO_TASK_ID = 'none';

const props = withDefaults(
    defineProps<{
        projectIds?: string[];
        // Tasks with time in the report's date range. When set, the others are hidden behind the
        // toggle instead of the done ones.
        idsInRange?: Set<string>;
    }>(),
    {
        projectIds: () => [],
        idsInRange: undefined,
    }
);

const model = defineModel<string[]>({ required: true });

const { tasks } = useTasksQuery();

const taskOptions = computed(() => buildTaskFilterOptions(tasks.value, props.projectIds));

const selectedOptionKeys = computed<string[]>({
    get() {
        const selected = getSelectedTaskOptionKeys(taskOptions.value, model.value);
        return model.value.includes(NO_TASK_ID) ? [NO_TASK_ID, ...selected] : selected;
    },
    set(optionKeys) {
        model.value = [
            ...(optionKeys.includes(NO_TASK_ID) ? [NO_TASK_ID] : []),
            ...getTaskIdsForOptionKeys(taskOptions.value, optionKeys),
        ];
    },
});

function getKeyFromItem(item: TaskFilterOption) {
    return item.key;
}

function getNameForItem(item: TaskFilterOption) {
    return item.name;
}

function getDateForItem(item: TaskFilterOption) {
    return item.lastActivityAt;
}

function isItemInactive(item: TaskFilterOption) {
    const idsInRange = props.idsInRange;
    return idsInRange ? !item.taskIds.some((id) => idsInRange.has(id)) : item.isDone;
}

const emit = defineEmits<{
    submit: [];
}>();

watch(
    () => props.projectIds,
    () => {
        if (tasks.value.length === 0 || model.value.length === 0) {
            return;
        }

        const allOptions = buildTaskFilterOptions(tasks.value, []);
        const selectedNames = getSelectedTaskOptionKeys(allOptions, model.value);
        const nextModel = [
            ...(model.value.includes(NO_TASK_ID) ? [NO_TASK_ID] : []),
            ...getTaskIdsForOptionKeys(taskOptions.value, selectedNames),
        ];

        if (nextModel.join() !== model.value.join()) {
            model.value = nextModel;
            emit('submit');
        }
    },
    { deep: true }
);
</script>

<template>
    <MultiselectDropdown
        v-model="selectedOptionKeys"
        search-placeholder="Search for a Task..."
        :items="taskOptions"
        :get-key-from-item="getKeyFromItem"
        :get-name-for-item="getNameForItem"
        :get-date-for-item="getDateForItem"
        :is-item-inactive="isItemInactive"
        :inactive-label="idsInRange ? 'Show outside date range' : 'Show done'"
        no-item-label="No Task"
        @submit="emit('submit')">
        <template #trigger>
            <slot
                name="trigger"
                :count="selectedOptionKeys.length"
                :active="selectedOptionKeys.length > 0"></slot>
        </template>
    </MultiselectDropdown>
</template>
