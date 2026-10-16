<script setup lang="ts">
import { computed } from 'vue';
import { debounceFilter, useStorage } from '@vueuse/core';
import {
    getCoreRowModel,
    useVueTable,
    type ColumnDef,
    type ColumnSizingState,
    type Updater,
} from '@tanstack/vue-table';
import { Target } from '@lucide/vue';
import type { Goal } from '@/packages/api/src';
import GoalTableRow from '@/Components/Common/Goal/GoalTableRow.vue';
import DetailedReportTableHeading from '@/Components/Common/Reporting/DetailedReportTableHeading.vue';
import GoalCreateButton from '@/Components/Common/Goal/GoalCreateButton.vue';
import { canCreateGoals } from '@/utils/permissions';
import { LoadingSpinner } from '@/packages/ui/src';
import { isGoalsExtensionActivated } from '@/utils/billing';

const props = defineProps<{
    goals: Goal[];
    isLoading: boolean;
    canCreateMoreGoals: boolean;
    showingArchived: boolean;
}>();

const showTargetColumn = isGoalsExtensionActivated();

const columnSizing = useStorage<ColumnSizingState>('goal-table-column-sizing', {}, undefined, {
    eventFilter: debounceFilter(250),
});
const columns: ColumnDef<Goal>[] = [
    { id: 'name', header: 'Goal', size: 260, minSize: 120 },
    ...(showTargetColumn ? [{ id: 'for', header: 'For', size: 140, minSize: 80 }] : []),
    { id: 'target', header: 'Target', size: 220, minSize: 120 },
    { id: 'scope', header: 'Scope', size: 200, minSize: 100 },
    { id: 'progress', header: 'Progress', size: 200, minSize: 100 },
    { id: 'status', header: 'Status', size: 130, minSize: 90 },
    { id: 'actions', header: '', size: 80, minSize: 80, enableResizing: false },
];

// TanStack only holds the column sizes, the rows are rendered by GoalTableRow
const table = useVueTable<Goal>({
    get data() {
        return props.goals;
    },
    columns,
    getCoreRowModel: getCoreRowModel(),
    enableColumnResizing: true,
    columnResizeMode: 'onChange',
    state: {
        get columnSizing() {
            return columnSizing.value;
        },
    },
    onColumnSizingChange: (updater: Updater<ColumnSizingState>) => {
        columnSizing.value = typeof updater === 'function' ? updater(columnSizing.value) : updater;
    },
});

const headers = computed(() => table.getFlatHeaders());
// The last track fills any leftover width, so resizing a column never stretches the others
const gridTemplateColumns = computed(
    () => `${headers.value.map((header) => `${header.getSize()}px`).join(' ')} minmax(0, 1fr)`
);
</script>

<template>
    <div class="flow-root max-w-[100vw] overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div data-testid="goal_table" class="grid min-w-full" :style="{ gridTemplateColumns }">
                <DetailedReportTableHeading :headers="headers"></DetailedReportTableHeading>
                <div
                    v-if="props.isLoading"
                    class="col-span-full flex justify-center items-center py-24">
                    <LoadingSpinner></LoadingSpinner>
                </div>
                <div v-else-if="props.goals.length === 0" class="col-span-full py-24 text-center">
                    <Target class="w-8 h-8 text-icon-default inline pb-2"></Target>
                    <h3 class="text-text-primary font-semibold">
                        {{ props.showingArchived ? 'No archived goals found' : 'No goals found' }}
                    </h3>
                    <p v-if="canCreateGoals() && !props.showingArchived" class="pb-5">
                        Set yourself a target and track your progress every day, week or month.
                    </p>
                    <GoalCreateButton
                        v-if="canCreateGoals() && !props.showingArchived"
                        :can-create-more-goals="props.canCreateMoreGoals"
                        >Create your first goal
                    </GoalCreateButton>
                </div>
                <template v-for="goal in props.goals" :key="goal.id">
                    <GoalTableRow
                        :goal="goal"
                        :show-target-column="showTargetColumn"></GoalTableRow>
                </template>
            </div>
        </div>
    </div>
</template>
