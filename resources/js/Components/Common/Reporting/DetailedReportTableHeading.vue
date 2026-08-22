<script setup lang="ts">
import TableHeading from '@/Components/Common/TableHeading.vue';
import type { Header } from '@tanstack/vue-table';
import type { DetailedReportRow } from '@/Components/Common/Reporting/DetailedReportTable.vue';

defineProps<{
    headers: Header<DetailedReportRow, unknown>[];
}>();

function label(header: Header<DetailedReportRow, unknown>): string {
    const header_ = header.column.columnDef.header;
    return typeof header_ === 'string' ? header_ : '';
}
</script>

<template>
    <TableHeading>
        <div
            v-for="(header, index) in headers"
            :key="header.id"
            :data-column="header.id"
            class="relative min-w-0 overflow-hidden py-1.5 px-3 text-left text-text-tertiary select-none flex items-center"
            :class="index === 0 ? 'pl-4 sm:pl-6 lg:pl-8' : ''">
            <span class="truncate">{{ label(header) }}</span>
            <!--
                Column resize grip. TanStack owns the drag maths (columnResizeMode: 'onChange'),
                we only forward the pointer events and paint the handle.
            -->
            <div
                v-if="header.column.getCanResize()"
                role="separator"
                aria-orientation="vertical"
                :aria-label="`Resize ${label(header)} column`"
                class="absolute top-0 right-0 h-full w-1.5 cursor-col-resize touch-none select-none transition-colors hover:bg-accent-300/50"
                :class="header.column.getIsResizing() ? 'bg-accent-300/70' : ''"
                @mousedown="header.getResizeHandler()($event)"
                @touchstart="header.getResizeHandler()($event)"
                @dblclick="header.column.resetSize()"></div>
        </div>
        <!-- Filler cell so the heading background spans any leftover width. -->
        <div></div>
    </TableHeading>
</template>

<style scoped></style>
