<script setup lang="ts">
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { PlusIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import ProjectTable from '@/Components/Common/Project/ProjectTable.vue';
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useProjectsStore } from '@/utils/useProjects';
import ProjectCreateModal from '@/packages/ui/src/Project/ProjectCreateModal.vue';
import { canCreateProjects } from '@/utils/permissions';
import { useClientsQuery } from '@/utils/useClientsQuery';
import { useClientsStore } from '@/utils/useClients';
import {
    api,
    type CreateClientBody,
    type Client,
    type CreateProjectBody,
    type Project,
} from '@/packages/api/src';
import { getOrganizationCurrencyString } from '@/utils/money';
import { getCurrentOrganizationId, getCurrentRole } from '@/utils/useUser';
import { useOrganizationQuery } from '@/utils/useOrganizationQuery';
import { isAllowedToPerformPremiumAction } from '@/utils/billing';
import { useStorage } from '@vueuse/core';
import ProjectsFilterDropdown from '@/Components/Common/Project/ProjectsFilterDropdown.vue';
import ProjectStatusFilterBadge from '@/Components/Common/Project/ProjectStatusFilterBadge.vue';
import ProjectVisibilityFilterBadge from '@/Components/Common/Project/ProjectVisibilityFilterBadge.vue';
import ProjectClientFilterBadge from '@/Components/Common/Project/ProjectClientFilterBadge.vue';
import { NO_CLIENT_ID } from '@/Components/Common/Project/constants';
import type { SortColumn, SortDirection } from '@/Components/Common/Project/ProjectTable.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { useTasksQuery } from '@/utils/useTasksQuery';
import { projectMatchesSearch } from '@/utils/projectSearch';
import ProjectDetailedPdfExportModal from '@/Components/Common/Project/ProjectDetailedPdfExportModal.vue';
import ReportingExportModal from '@/Components/Common/Reporting/ReportingExportModal.vue';
import UpgradeModal from '@/Components/Common/UpgradeModal.vue';
import { useNotificationsStore } from '@/utils/notification';
import { getDayJsInstance, getLocalizedDayJs } from '@/packages/ui/src/utils/time';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';

// Fetch data using TanStack Query
const { projects } = useProjectsQuery();
const { clients } = useClientsQuery();
const { tasks } = useTasksQuery();
const { organization } = useOrganizationQuery(getCurrentOrganizationId()!);
const sortedClients = computed(() =>
    [...clients.value].sort((a, b) => a.name.localeCompare(b.name))
);

// Table state persisted in localStorage
interface ProjectTableState {
    sortColumn: SortColumn;
    sortDirection: SortDirection;
    filters: {
        clientIds: string[];
        status: 'active' | 'archived' | 'all';
        visibility: 'public' | 'private' | 'all';
    };
}

const tableState = useStorage<ProjectTableState>(
    'project-table-state-v2',
    {
        sortColumn: 'name',
        sortDirection: 'asc',
        filters: {
            clientIds: [],
            status: 'active',
            visibility: 'all',
        },
    },
    undefined,
    {
        mergeDefaults: (storage, defaults) => ({
            ...defaults,
            ...storage,
            filters: { ...defaults.filters, ...storage.filters },
        }),
    }
);

function handleSort(column: SortColumn, direction: SortDirection) {
    tableState.value.sortColumn = column;
    tableState.value.sortDirection = direction;
}

// Keep each organization's search when navigating into a project and back.
const search = useStorage(`project-search-${getCurrentOrganizationId() ?? 'default'}`, '');
const filterStartDate = ref(
    getLocalizedDayJs(getDayJsInstance()().format()).startOf('year').format()
);
const filterEndDate = ref(getLocalizedDayJs(getDayJsInstance()().format()).format());
const activityQuery = useQuery({
    queryKey: computed(() => ['project-activity', filterStartDate.value, filterEndDate.value]),
    queryFn: async () => {
        const ids = new Set<string>();
        let offset = 0;
        let total = 0;
        do {
            const response = await api.getTimeEntries({
                params: { organization: getCurrentOrganizationId()! },
                queries: {
                    start: getLocalizedDayJs(filterStartDate.value).startOf('day').utc().format(),
                    end: getLocalizedDayJs(filterEndDate.value).endOf('day').utc().format(),
                    active: 'false',
                    limit: 500,
                    offset,
                },
            });
            response.data.forEach((entry) => entry.project_id && ids.add(entry.project_id));
            total = response.meta.total;
            offset += response.data.length;
        } while (offset < total);
        return ids;
    },
});

const clientNames = computed(
    () => new Map(clients.value.map((client) => [client.id, client.name]))
);
const taskNamesByProject = computed(() => {
    const names = new Map<string, string[]>();

    for (const task of tasks.value) {
        const projectTasks = names.get(task.project_id) ?? [];
        projectTasks.push(task.name);
        names.set(task.project_id, projectTasks);
    }

    return names;
});

// Filter projects based on current filters
const filteredProjects = computed(() => {
    const searchTerm = search.value.trim().toLowerCase();

    return projects.value.filter((project) => {
        if (activityQuery.data.value && !activityQuery.data.value.has(project.id)) return false;
        // Search all useful project details, including related client and task names.
        if (
            searchTerm &&
            !projectMatchesSearch(
                project,
                searchTerm,
                project.client_id ? clientNames.value.get(project.client_id) : undefined,
                taskNamesByProject.value.get(project.id) ?? []
            )
        ) {
            return false;
        }

        // Status filter
        if (tableState.value.filters.status === 'active' && project.is_archived) {
            return false;
        }
        if (tableState.value.filters.status === 'archived' && !project.is_archived) {
            return false;
        }

        // Visibility filter
        if (tableState.value.filters.visibility === 'public' && !project.is_public) {
            return false;
        }
        if (tableState.value.filters.visibility === 'private' && project.is_public) {
            return false;
        }

        // Client filter
        const hasClientFilter = tableState.value.filters.clientIds.length > 0;
        if (hasClientFilter) {
            const matchesNoClient =
                tableState.value.filters.clientIds.includes(NO_CLIENT_ID) && !project.client_id;
            const matchesClientId =
                project.client_id && tableState.value.filters.clientIds.includes(project.client_id);

            if (!matchesNoClient && !matchesClientId) {
                return false;
            }
        }

        return true;
    });
});

// Helper functions for active filters
function removeStatusFilter() {
    tableState.value.filters.status = 'all';
}

function removeVisibilityFilter() {
    tableState.value.filters.visibility = 'all';
}

function removeClientFilter() {
    tableState.value.filters.clientIds = [];
}

const showCreateProjectModal = useStorage('project-create-modal-open', false);

async function createProject(project: CreateProjectBody): Promise<Project | undefined> {
    return await useProjectsStore().createProject(project);
}

async function createClient(client: CreateClientBody): Promise<Client | undefined> {
    return await useClientsStore().createClient(client);
}

const showBillableRate = computed(() => {
    return !!(
        getCurrentRole() !== 'employee' || organization.value?.employees_can_see_billable_rates
    );
});

const selectedExportProject = ref<Project | null>(null);
const showProjectExportModal = ref(false);
const showExportReadyModal = ref(false);
const showPremiumModal = ref(false);
const exportLoading = ref(false);
const exportUrl = ref<string | null>(null);
const exportStartDate = ref(getLocalizedDayJs('1970-01-01').startOf('day').format());
const exportEndDate = ref(getLocalizedDayJs(getDayJsInstance()().format()).format());
const { handleApiRequestNotifications } = useNotificationsStore();

function openDetailedPdfExport(project: Project) {
    if (!isAllowedToPerformPremiumAction()) {
        showPremiumModal.value = true;
        return;
    }

    selectedExportProject.value = project;
    showProjectExportModal.value = true;
}

async function exportDetailedPdf() {
    if (!selectedExportProject.value || exportLoading.value) return;

    const project = selectedExportProject.value;
    exportLoading.value = true;
    let response;

    try {
        response = await handleApiRequestNotifications(
            () =>
                api.exportTimeEntries({
                    params: { organization: getCurrentOrganizationId()! },
                    queries: {
                        format: 'pdf',
                        start: getLocalizedDayJs(exportStartDate.value)
                            .startOf('day')
                            .utc()
                            .format(),
                        end: getLocalizedDayJs(exportEndDate.value).endOf('day').utc().format(),
                        active: 'false',
                        project_ids: [project.id],
                    },
                }),
            'Project report created',
            'Project report export failed'
        );
    } finally {
        exportLoading.value = false;
    }

    if (response?.download_url) {
        exportUrl.value = response.download_url as string;
        showProjectExportModal.value = false;
        showExportReadyModal.value = true;
    }
}
</script>

<template>
    <AppLayout title="Projects" data-testid="projects_view">
        <ProjectDetailedPdfExportModal
            v-model:show="showProjectExportModal"
            v-model:start-date="exportStartDate"
            v-model:end-date="exportEndDate"
            :project="selectedExportProject"
            :loading="exportLoading"
            @export="exportDetailedPdf" />
        <ReportingExportModal v-model:show="showExportReadyModal" :export-url="exportUrl" />
        <UpgradeModal v-model:show="showPremiumModal">
            <strong>PDF Reports</strong> are only available in solidtime Professional.
        </UpgradeModal>
        <MainContainer class="py-3 sm:pt-5">
            <div class="flex flex-wrap items-center gap-2 py-1">
                <ProjectsFilterDropdown
                    :filters="tableState.filters"
                    :clients="sortedClients"
                    @update:filters="tableState.filters = $event" />

                <!-- Same style as the dropdown search inputs, see MultiselectDropdown.vue -->
                <TextInput
                    v-model="search"
                    type="search"
                    data-testid="project_search"
                    placeholder="Search projects, clients, tasks..."
                    class="w-60 h-8" />

                <DateRangePicker v-model:start="filterStartDate" v-model:end="filterEndDate" />

                <!-- Active Filters -->
                <ProjectStatusFilterBadge
                    v-if="tableState.filters.status !== 'all'"
                    data-testid="status-filter-badge"
                    :value="tableState.filters.status"
                    @remove="removeStatusFilter"
                    @update:value="
                        tableState.filters.status = $event as 'active' | 'archived' | 'all'
                    " />

                <ProjectVisibilityFilterBadge
                    v-if="tableState.filters.visibility !== 'all'"
                    data-testid="visibility-filter-badge"
                    :value="tableState.filters.visibility"
                    @remove="removeVisibilityFilter"
                    @update:value="
                        tableState.filters.visibility = $event as 'public' | 'private' | 'all'
                    " />

                <ProjectClientFilterBadge
                    v-if="tableState.filters.clientIds.length > 0"
                    data-testid="client-filter-badge"
                    :value="tableState.filters.clientIds"
                    :clients="sortedClients"
                    @remove="removeClientFilter"
                    @update:value="tableState.filters.clientIds = $event as string[]" />

                <!-- Pushed to the right edge, aligned with the filter/search row -->
                <SecondaryButton
                    v-if="canCreateProjects()"
                    :icon="PlusIcon"
                    class="ml-auto"
                    @click="showCreateProjectModal = true"
                    >Create Project
                </SecondaryButton>
                <ProjectCreateModal
                    v-model:show="showCreateProjectModal"
                    :create-project
                    :enable-estimated-time="isAllowedToPerformPremiumAction()"
                    :create-client
                    :currency="getOrganizationCurrencyString()"
                    :organization-billable-rate="organization?.billable_rate ?? null"
                    :clients="sortedClients"
                    @submit="createProject"></ProjectCreateModal>
            </div>
        </MainContainer>

        <ProjectTable
            :show-billable-rate="showBillableRate"
            :projects="filteredProjects"
            :sort-column="tableState.sortColumn"
            :sort-direction="tableState.sortDirection"
            @export-detailed-pdf="openDetailedPdfExport"
            @sort="handleSort"></ProjectTable>
    </AppLayout>
</template>
