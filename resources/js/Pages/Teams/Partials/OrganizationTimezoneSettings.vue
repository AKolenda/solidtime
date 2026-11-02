<script setup lang="ts">
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import TimezoneCombobox from '@/Components/Common/TimezoneCombobox.vue';
import { Switch } from '@/Components/ui/switch';
import { Checkbox } from '@/packages/ui/src';
import { Field, FieldDescription, FieldLabel } from '@/packages/ui/src/field';
import { useOrganizationStore } from '@/utils/useOrganization';
import { storeToRefs } from 'pinia';
import { useMutation, useQueryClient } from '@tanstack/vue-query';
import { router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const store = useOrganizationStore();
const { updateOrganization } = store;
const { organization } = storeToRefs(store);
const queryClient = useQueryClient();

const timezone = ref('');
const applyToMembers = ref(true);
const locked = ref(false);

onMounted(() => {
    timezone.value = organization.value?.timezone ?? '';
    locked.value = organization.value?.timezone_locked ?? false;
    // Once a timezone is set, pushing it again should be a deliberate choice
    applyToMembers.value = !organization.value?.timezone;
});

// Locking always moves everyone onto the timezone
const applies = computed(() => locked.value || applyToMembers.value);

const mutation = useMutation({
    mutationFn: async () => {
        await updateOrganization({
            timezone: timezone.value,
            timezone_locked: locked.value,
            apply_timezone_to_members: applies.value,
        });
    },
    onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['organization'] });
        queryClient.invalidateQueries({ queryKey: ['me'] });
        // The app reads the signed-in user's timezone from the page props
        router.reload({ only: ['auth'] });
        applyToMembers.value = false;
    },
});
</script>

<template>
    <FormSection>
        <template #title>Timezone</template>
        <template #description>
            Set one timezone for everyone in {{ organization?.name }}. Reports, the calendar and the
            timesheet use each member's timezone to place their hours on the right day.
        </template>

        <template #form>
            <div class="col-span-6 sm:col-span-4 space-y-5">
                <Field>
                    <FieldLabel for="organizationTimezone">Organization timezone</FieldLabel>
                    <TimezoneCombobox id="organizationTimezone" v-model="timezone" />
                </Field>
                <Field orientation="horizontal" class="items-start">
                    <Checkbox
                        id="applyTimezoneToMembers"
                        :checked="applies"
                        :disabled="locked"
                        @update:checked="applyToMembers = $event" />
                    <div class="space-y-1">
                        <FieldLabel for="applyTimezoneToMembers"
                            >Apply to all members now</FieldLabel
                        >
                        <FieldDescription>
                            Changes the timezone of everyone in this organization, including you.
                        </FieldDescription>
                    </div>
                </Field>
                <Field orientation="horizontal" class="items-start">
                    <Switch
                        id="lockTimezone"
                        v-model="locked"
                        class="data-[state=checked]:bg-accent-500" />
                    <div class="space-y-1">
                        <FieldLabel for="lockTimezone">Lock timezone</FieldLabel>
                        <FieldDescription v-if="locked">
                            Members can't change their own timezone, people who join are moved to
                            it, and the "your browser's timezone is different" prompt stops showing.
                        </FieldDescription>
                        <FieldDescription v-else>
                            Members can change their own timezone afterwards.
                        </FieldDescription>
                    </div>
                </Field>
            </div>
        </template>

        <template #actions>
            <PrimaryButton
                :disabled="mutation.isPending.value || timezone === ''"
                @click="mutation.mutate()"
                >Save</PrimaryButton
            >
        </template>
    </FormSection>
</template>
