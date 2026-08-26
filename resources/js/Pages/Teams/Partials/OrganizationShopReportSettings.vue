<script setup lang="ts">
import FormSection from '@/Components/FormSection.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import { Checkbox } from '@/packages/ui/src';
import { ref, watch } from 'vue';
import { useOrganizationStore } from '@/utils/useOrganization';
import { storeToRefs } from 'pinia';
import { useMutation, useQueryClient } from '@tanstack/vue-query';
import type { UpdateOrganizationBody } from '@/packages/api/src';

const store = useOrganizationStore();
const { organization } = storeToRefs(store);
const queryClient = useQueryClient();
const enabled = ref(false);
const logo = ref<string | null>(null);
const fileError = ref<string | null>(null);

watch(
    organization,
    (value) => {
        enabled.value = value?.shop_report_enabled ?? false;
        logo.value = value?.shop_report_logo ?? null;
    },
    { immediate: true }
);

const mutation = useMutation({
    mutationFn: (values: Partial<UpdateOrganizationBody>) => store.updateOrganization(values),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['organization'] }),
});

function selectLogo(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    fileError.value = null;
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        fileError.value = 'Choose a PNG, JPEG, or WebP image.';
        return;
    }
    if (file.size > 1024 * 1024) {
        fileError.value = 'The logo must be smaller than 1 MB.';
        return;
    }
    const reader = new FileReader();
    reader.onload = () => (logo.value = String(reader.result));
    reader.readAsDataURL(file);
}

async function submit() {
    await mutation.mutateAsync({
        shop_report_enabled: enabled.value,
        shop_report_logo: logo.value,
    });
}
</script>

<template>
    <FormSection>
        <template #title>Shop PDF reports</template>
        <template #description>
            Format single-project PDF exports for production quoting and job review.
        </template>
        <template #form>
            <div class="col-span-6 sm:col-span-4 space-y-5">
                <Field orientation="horizontal">
                    <Checkbox id="shopReportEnabled" v-model:checked="enabled" />
                    <FieldLabel for="shopReportEnabled">Use the shop report layout</FieldLabel>
                </Field>
                <div class="space-y-2">
                    <FieldLabel for="shopReportLogo">Report logo</FieldLabel>
                    <input
                        id="shopReportLogo"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="block w-full rounded-md border border-border bg-background px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-card-background file:px-3 file:py-1.5 file:text-text-primary"
                        @change="selectLogo" />
                    <p class="text-xs text-text-secondary">PNG, JPEG, or WebP. Maximum 1 MB.</p>
                    <p v-if="fileError" class="text-sm text-destructive">{{ fileError }}</p>
                    <div v-if="logo" class="rounded-lg border border-border bg-white p-4">
                        <img
                            :src="logo"
                            alt="Report logo preview"
                            class="max-h-20 max-w-full object-contain" />
                    </div>
                    <SecondaryButton v-if="logo" type="button" @click="logo = null">
                        Remove logo
                    </SecondaryButton>
                </div>
            </div>
        </template>
        <template #actions>
            <PrimaryButton :disabled="mutation.isPending.value" @click="submit">Save</PrimaryButton>
        </template>
    </FormSection>
</template>
