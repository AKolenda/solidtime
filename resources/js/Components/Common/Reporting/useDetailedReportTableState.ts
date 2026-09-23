import { createGlobalState, debounceFilter, useStorage } from '@vueuse/core';
import type { ColumnSizingState } from '@tanstack/vue-table';

/**
 * Column widths and the grouping toggle survive a reload. Widths are keyed by
 * column id, so adding or removing a column later just falls back to its default size.
 */
export interface DetailedReportTableState {
    columnSizing: ColumnSizingState;
    collapseDuplicates: boolean;
}

/**
 * Shared between the table and the report toolbar, which renders the grouping and
 * width-reset controls outside the table.
 */
export const useDetailedReportTableState = createGlobalState(() =>
    useStorage<DetailedReportTableState>(
        'detailed-report-table-state',
        {
            columnSizing: {},
            collapseDuplicates: true,
        },
        undefined,
        // `columnResizeMode: 'onChange'` fires on every mouse move, so persistence is debounced
        // while the in-memory value stays immediate.
        { mergeDefaults: true, eventFilter: debounceFilter(250) }
    )
);
