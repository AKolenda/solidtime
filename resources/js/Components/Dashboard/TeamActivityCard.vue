<script lang="ts" setup>
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, ref } from 'vue';
import { useNow } from '@vueuse/core';
import DashboardCard from '@/Components/Dashboard/DashboardCard.vue';
import TeamActivityCardEntry from '@/Components/Dashboard/TeamActivityCardEntry.vue';
import { UserGroupIcon } from '@heroicons/vue/20/solid';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import TabBar from '@/packages/ui/src/TabBar/TabBar.vue';
import TabBarItem from '@/packages/ui/src/TabBar/TabBarItem.vue';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { getCurrentMembershipId, getCurrentOrganizationId } from '@/utils/useUser';
import { canUpdateTimeEntries } from '@/utils/permissions';
import { useTimeEntriesMutations } from '@/utils/useTimeEntriesMutations';
import { useCurrentTimeEntryStore } from '@/utils/useCurrentTimeEntry';
import { api } from '@/packages/api/src';
import { LoadingSpinner } from '@/packages/ui/src';
import { getDayJsInstance } from '@/packages/ui/src/utils/time';
import { router } from '@inertiajs/vue3';

// Get the organization ID using the utility function
const organizationId = computed(() => getCurrentOrganizationId());

// Set up the query
const { data: latestTeamActivity, isLoading } = useQuery({
    queryKey: ['latestTeamActivity', organizationId],
    queryFn: () => {
        return api.latestTeamActivity({
            params: {
                organization: organizationId.value!,
            },
        });
    },
    enabled: computed(() => !!organizationId.value),
    refetchInterval: 60000,
});

type TeamActivity = NonNullable<typeof latestTeamActivity.value>[number];

const now = useNow({ interval: 30000 });

const activeTab = ref<'working' | 'everyone'>('working');
const working = computed(
    () => latestTeamActivity.value?.filter((activity) => activity.status) ?? []
);
const shownActivity = computed(() =>
    activeTab.value === 'working' ? working.value : (latestTeamActivity.value ?? [])
);

const queryClient = useQueryClient();
const { updateTimeEntry } = useTimeEntriesMutations();
const stopCandidate = ref<TeamActivity | null>(null);
const showStopDialog = ref(false);

function askToStop(activity: TeamActivity) {
    stopCandidate.value = activity;
    showStopDialog.value = true;
}

async function stopTimer() {
    const activity = stopCandidate.value;
    if (activity === null) {
        return;
    }
    await updateTimeEntry({
        id: activity.time_entry_id,
        end: getDayJsInstance()().utc().format('YYYY-MM-DDTHH:mm:ss[Z]'),
    });
    await queryClient.invalidateQueries({ queryKey: ['latestTeamActivity'] });
    if (activity.member_id === getCurrentMembershipId()) {
        await useCurrentTimeEntryStore().fetchCurrentTimeEntry();
    }
}
</script>

<template>
    <DashboardCard title="Team Activity" :icon="UserGroupIcon">
        <div v-if="isLoading" class="flex justify-center items-center h-40">
            <LoadingSpinner />
        </div>
        <template v-else-if="latestTeamActivity && latestTeamActivity.length > 0">
            <div class="px-2.5 pt-2.5 pb-1.5">
                <TabBar v-model="activeTab">
                    <TabBarItem
                        value="working"
                        class="flex-1 data-[state=active]:bg-card-background-active">
                        Working · {{ working.length }}
                    </TabBarItem>
                    <TabBarItem
                        value="everyone"
                        class="flex-1 data-[state=active]:bg-card-background-active">
                        Everyone · {{ latestTeamActivity.length }}
                    </TabBarItem>
                </TabBar>
            </div>
            <div class="max-h-64 overflow-y-auto">
                <TeamActivityCardEntry
                    v-for="activity in shownActivity"
                    :key="activity.time_entry_id"
                    :name="activity.name"
                    :member-id="activity.member_id"
                    :description="activity.description"
                    :project-id="activity.project_id"
                    :project-name="activity.project_name"
                    :project-color="activity.project_color"
                    :task-name="activity.task_name"
                    :start="activity.start"
                    :end="activity.end"
                    :working="activity.status"
                    :now="now.getTime()"
                    :can-stop="canUpdateTimeEntries()"
                    @stop="askToStop(activity)"></TeamActivityCardEntry>
                <p
                    v-if="shownActivity.length === 0"
                    class="text-center text-sm text-text-secondary py-6">
                    Nobody is clocked in right now.
                </p>
            </div>
        </template>
        <div v-else class="text-center text-gray-500 py-8">No team activity found</div>
        <div
            v-if="latestTeamActivity && latestTeamActivity.length <= 1"
            :class="latestTeamActivity?.length === 1 ? 'pb-5' : 'py-5'"
            class="text-center flex flex-1 justify-center items-center">
            <div>
                <UserGroupIcon class="w-8 text-icon-default inline pb-2"></UserGroupIcon>
                <h3 class="text-text-primary font-medium text-sm">Invite your co-workers</h3>
                <p class="pb-5 text-sm">You can invite your entire team.</p>
                <SecondaryButton @click="router.visit(route('members'))"
                    >Go to Members
                </SecondaryButton>
            </div>
        </div>
        <AlertDialog v-model:open="showStopDialog">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Stop {{ stopCandidate?.name }}'s timer?</AlertDialogTitle>
                    <AlertDialogDescription>
                        The running time entry ends now. You can correct the times afterwards in the
                        Detailed report.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        @click="stopTimer">
                        Stop timer
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </DashboardCard>
</template>
