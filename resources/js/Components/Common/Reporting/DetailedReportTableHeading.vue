<script setup lang="ts" generic="Row">
import TableHeading from '@/Components/Common/TableHeading.vue';
import type { Header } from '@tanstack/vue-table';

defineProps<{
    headers: Header<Row, unknown>[];
}>();

function label(header: Header<Row, unknown>): string {
    const header_ = header.column.columnDef.header;
    return typeof header_ === 'string' ? header_ : '';
}

function alignsRight(header: Header<Row, unknown>): boolean {
    return (header.column.columnDef.meta as { align?: 'right' } | undefined)?.align === 'right';
}
</script>

<template>
    <TableHeading>
        <div
            v-for="(header, index) in headers"
            :key="header.id"
            :data-column="header.id"
            class="relative min-w-0 overflow-hidden py-1.5 px-3 text-left text-text-tertiary select-none flex items-center"
            :class="[
                index === 0 ? 'pl-4 sm:pl-6 lg:pl-8' : '',
                alignsRight(header) ? 'justify-end' : '',
            ]">
            <slot v-if="$slots[header.id]" :name="header.id"></slot>
            <span v-else class="truncate">{{ label(header) }}</span>
            <!--
                Column resize grip. TanStack owns the drag maths (columnResizeMode: 'onChange'),
                we only forward the pointer events and paint the handle.
            -->
            <div
                v-if="header.column.getCanResize()"
                role="separator"
                aria-orientation="vertical"
                :aria-label="`Resize ${label(header)} column`"
                class="group/resize absolute inset-y-0 right-0 z-10 w-3 cursor-col-resize touch-none select-none"
                @mousedown="header.getResizeHandler()($event)"
                @touchstart="header.getResizeHandler()($event)"
                @dblclick="header.column.resetSize()">
                <span
                    class="absolute inset-y-1 right-0 w-px transition-colors duration-200 group-hover/resize:bg-accent-400"
                    :class="
                        header.column.getIsResizing() ? 'bg-accent-400' : 'bg-transparent'
                    "></span>
            </div>
        </div>
        <!-- Filler cell so the heading background spans any leftover width. -->
        <div></div>
    </TableHeading>
</template>

<style scoped></style>
