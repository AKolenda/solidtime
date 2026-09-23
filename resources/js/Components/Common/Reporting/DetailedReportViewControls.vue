<script setup lang="ts">
import { Checkbox } from '@/packages/ui/src';
import { useDetailedReportTableState } from './useDetailedReportTableState';

const tableState = useDetailedReportTableState();
</script>

<template>
    <div class="flex items-center gap-3">
        <label
            class="flex min-h-8 items-center gap-2 whitespace-nowrap text-xs text-text-secondary hover:text-text-primary transition-colors cursor-pointer select-none">
            <Checkbox
                :checked="tableState.collapseDuplicates"
                @update:checked="tableState.collapseDuplicates = $event === true" />
            Group identical
        </label>
        <button
            type="button"
            class="min-h-8 whitespace-nowrap text-xs text-text-secondary hover:text-text-primary transition-colors"
            @click="tableState.columnSizing = {}">
            Reset widths
        </button>
    </div>
</template>
