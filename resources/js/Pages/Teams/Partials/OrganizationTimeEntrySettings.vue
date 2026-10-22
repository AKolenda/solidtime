<script setup lang="ts">
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { onMounted, ref } from 'vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import {
    Checkbox,
    NumberField,
    NumberFieldContent,
    NumberFieldDecrement,
    NumberFieldIncrement,
    NumberFieldInput,
} from '@/packages/ui/src';
import type { UpdateOrganizationBody } from '@/packages/api/src';
import { useOrganizationStore } from '@/utils/useOrganization';
import { storeToRefs } from 'pinia';
import { useMutation, useQueryClient } from '@tanstack/vue-query';

const store = useOrganizationStore();
const { updateOrganization } = store;
const { organization } = storeToRefs(store);
const queryClient = useQueryClient();

const form = ref<{
    prevent_overlapping_time_entries: boolean;
    employees_can_manage_tasks: boolean;
    breaks_enabled: boolean;
    auto_stop_enabled: boolean;
    auto_stop_hours: number;
}>({
    prevent_overlapping_time_entries: false,
    employees_can_manage_tasks: false,
    breaks_enabled: false,
    auto_stop_enabled: false,
    auto_stop_hours: 16,
});

onMounted(async () => {
    form.value.prevent_overlapping_time_entries =
        organization.value?.prevent_overlapping_time_entries ?? false;
    form.value.employees_can_manage_tasks = organization.value?.employees_can_manage_tasks ?? false;
    form.value.breaks_enabled = organization.value?.breaks_enabled ?? false;
    form.value.auto_stop_enabled = !!organization.value?.time_entry_auto_stop_hours;
    form.value.auto_stop_hours = organization.value?.time_entry_auto_stop_hours ?? 16;
});

const mutation = useMutation({
    mutationFn: (values: Partial<UpdateOrganizationBody>) => updateOrganization(values),
    onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: ['organization'] });
    },
});

async function submit() {
    await mutation.mutateAsync({
        prevent_overlapping_time_entries: form.value.prevent_overlapping_time_entries,
        employees_can_manage_tasks: form.value.employees_can_manage_tasks,
        breaks_enabled: form.value.breaks_enabled,
        time_entry_auto_stop_hours: form.value.auto_stop_enabled
            ? form.value.auto_stop_hours
            : null,
    });
}
</script>

<template>
    <FormSection>
        <template #title>Organization Settings</template>
        <template #description>
            Configure various settings for your organization, including time entry and task
            management permissions.
        </template>

        <template #form>
            <div class="col-span-6 sm:col-span-4 space-y-4">
                <Field orientation="horizontal">
                    <Checkbox
                        id="preventOverlappingTimeEntries"
                        v-model:checked="form.prevent_overlapping_time_entries" />
                    <FieldLabel for="preventOverlappingTimeEntries"
                        >Prevent overlapping time entries (new entries only)</FieldLabel
                    >
                </Field>
                <Field orientation="horizontal">
                    <Checkbox
                        id="employeesCanManageTasks"
                        v-model:checked="form.employees_can_manage_tasks" />
                    <FieldLabel for="employeesCanManageTasks"
                        >Allow Employees to manage tasks</FieldLabel
                    >
                </Field>
                <Field orientation="horizontal">
                    <Checkbox id="breaksEnabled" v-model:checked="form.breaks_enabled" />
                    <FieldLabel for="breaksEnabled">Allow tracking breaks</FieldLabel>
                </Field>
                <Field orientation="horizontal">
                    <Checkbox id="autoStopEnabled" v-model:checked="form.auto_stop_enabled" />
                    <FieldLabel for="autoStopEnabled"
                        >Stop running timers automatically after</FieldLabel
                    >
                    <NumberField
                        id="autoStopHours"
                        v-model="form.auto_stop_hours"
                        :min="1"
                        :max="168"
                        :disabled="!form.auto_stop_enabled"
                        aria-label="Hours before running timers are stopped"
                        class="w-28 text-sm">
                        <NumberFieldContent>
                            <NumberFieldDecrement :disabled="!form.auto_stop_enabled" />
                            <NumberFieldInput :disabled="!form.auto_stop_enabled" />
                            <NumberFieldIncrement :disabled="!form.auto_stop_enabled" />
                        </NumberFieldContent>
                    </NumberField>
                    <span class="text-sm text-text-secondary">hours</span>
                </Field>
            </div>
        </template>

        <template #actions>
            <PrimaryButton :disabled="mutation.isPending.value" @click="submit">Save</PrimaryButton>
        </template>
    </FormSection>
</template>
