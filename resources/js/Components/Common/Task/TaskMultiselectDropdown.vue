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
    }>(),
    {
        projectIds: () => [],
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
