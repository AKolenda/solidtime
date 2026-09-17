<script setup lang="ts">
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { UserCircleIcon } from '@heroicons/vue/24/solid';
import { PlusIcon } from '@heroicons/vue/16/solid';
import { type Component, computed, ref, watch } from 'vue';
import { type Client } from '@/packages/api/src';
import ClientTableRow from '@/Components/Common/Client/ClientTableRow.vue';
import ClientCreateModal from '@/Components/Common/Client/ClientCreateModal.vue';
import ClientTableHeading from '@/Components/Common/Client/ClientTableHeading.vue';
import Pagination from '@/Components/Common/Pagination.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import { useStorage } from '@vueuse/core';
import { canCreateClients } from '@/utils/permissions';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import {
    useVueTable,
    getCoreRowModel,
    getSortedRowModel,
    type SortingState,
} from '@tanstack/vue-table';

export type SortColumn = 'name' | 'projects_count' | 'status';
export type SortDirection = 'asc' | 'desc';

const props = defineProps<{
    clients: Client[];
    sortColumn: SortColumn;
    sortDirection: SortDirection;
}>();

const emit = defineEmits<{
    sort: [column: SortColumn, direction: SortDirection];
}>();

const createClient = ref(false);

const { projects } = useProjectsQuery();

const projectCountMap = computed(() => {
    const map = new Map<string, number>();
    projects.value.forEach((project) => {
        if (project.client_id) {
            map.set(project.client_id, (map.get(project.client_id) ?? 0) + 1);
        }
    });
    return map;
});

// Name is always the secondary sort so rows with equal values render
// alphabetically instead of in API (created_at) order.
const sorting = computed<SortingState>(() => [
    {
        id: props.sortColumn,
        desc: props.sortDirection === 'desc',
    },
    ...(props.sortColumn !== 'name' ? [{ id: 'name', desc: false }] : []),
]);

const columns = computed(() => [
    {
        id: 'name',
        accessorFn: (row: Client) => row.name.toLowerCase(),
    },
    {
        id: 'projects_count',
        sortDescFirst: true,
        accessorFn: (row: Client) => projectCountMap.value.get(row.id) ?? 0,
    },
    {
        id: 'status',
        accessorFn: (row: Client) => (row.is_archived ? 2 : row.is_closed ? 1 : 0),
    },
]);

const descFirstColumns = new Set<SortColumn>(
    columns.value
        .filter((c) => 'sortDescFirst' in c && c.sortDescFirst)
        .map((c) => c.id as SortColumn)
);

function handleSort(column: SortColumn) {
    if (props.sortColumn === column) {
        emit('sort', column, props.sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
        emit('sort', column, descFirstColumns.has(column) ? 'desc' : 'asc');
    }
}

const table = useVueTable({
    get data() {
        return props.clients;
    },
    get columns() {
        return columns.value;
    },
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    state: {
        get sorting() {
            return sorting.value;
        },
    },
    manualSorting: false,
});

const sortedClients = computed(() => {
    return table.getRowModel().rows.map((row) => row.original);
});

// Client-side pagination: the full list is in memory, only one page is mounted at a time.
type ClientPageSize = '25' | '50' | '100' | 'all';
const pageSize = useStorage<ClientPageSize>('client-table-page-size', '25');
const currentPage = ref(1);

const itemsPerPage = computed(() => {
    if (pageSize.value === 'all') {
        return Math.max(sortedClients.value.length, 1);
    }

    return Number(pageSize.value);
});

// Editing a client refetches the list as a new array; only a change in membership
// (tab switch, archive, delete) should send the user back to page 1.
watch(
    [() => props.sortColumn, () => props.sortDirection, () => props.clients.length, pageSize],
    () => {
        currentPage.value = 1;
    }
);

const paginatedClients = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage.value;
    return sortedClients.value.slice(start, start + itemsPerPage.value);
});

const firstVisibleClient = computed(() =>
    sortedClients.value.length === 0 ? 0 : (currentPage.value - 1) * itemsPerPage.value + 1
);
const lastVisibleClient = computed(() =>
    Math.min(currentPage.value * itemsPerPage.value, sortedClients.value.length)
);
</script>

<template>
    <ClientCreateModal v-model:show="createClient"></ClientCreateModal>
    <div class="flow-root max-w-[100vw] overflow-x-auto">
        <div class="inline-block min-w-full align-middle">
            <div
                data-testid="client_table"
                class="grid min-w-full"
                style="grid-template-columns: 1fr 150px 200px 80px">
                <ClientTableHeading
                    :sort-column="props.sortColumn"
                    :sort-direction="props.sortDirection"
                    :desc-first-columns="descFirstColumns"
                    @sort="handleSort"></ClientTableHeading>
                <div v-if="sortedClients.length === 0" class="col-span-3 py-24 text-center">
                    <UserCircleIcon class="w-8 text-icon-default inline pb-2"></UserCircleIcon>
                    <h3 class="text-text-primary font-semibold">No clients found</h3>
                    <p v-if="canCreateClients()" class="pb-5">Create your first client now!</p>
                    <SecondaryButton
                        v-if="canCreateClients()"
                        :icon="PlusIcon as Component"
                        @click="createClient = true"
                        >Create your First Client
                    </SecondaryButton>
                </div>
                <template v-for="client in paginatedClients" :key="client.id">
                    <ClientTableRow :client="client"></ClientTableRow>
                </template>
            </div>
        </div>
    </div>
    <div
        v-if="sortedClients.length > 0"
        class="grid grid-cols-1 items-center gap-3 px-4 py-4 sm:grid-cols-[1fr_auto_1fr] sm:px-6">
        <div class="flex items-center gap-2 text-sm text-text-secondary">
            <span class="whitespace-nowrap">Clients per page</span>
            <Select v-model="pageSize">
                <SelectTrigger
                    aria-label="Clients per page"
                    data-testid="client_page_size"
                    class="h-9 w-[82px] bg-card-background">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="25">25</SelectItem>
                    <SelectItem value="50">50</SelectItem>
                    <SelectItem value="100">100</SelectItem>
                    <SelectItem value="all">All</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <Pagination
            v-model:page="currentPage"
            class="!w-auto !py-0"
            :total="sortedClients.length"
            :items-per-page="itemsPerPage"></Pagination>

        <p class="text-sm text-text-secondary sm:text-right">
            Showing {{ firstVisibleClient }}–{{ lastVisibleClient }} of
            {{ sortedClients.length }}
        </p>
    </div>
</template>
