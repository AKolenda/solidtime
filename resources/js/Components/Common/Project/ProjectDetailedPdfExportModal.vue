<script setup lang="ts">
import type { Project } from '@/packages/api/src';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { PrimaryButton, SecondaryButton } from '@/packages/ui/src';

defineProps<{
    project: Project | null;
    loading: boolean;
}>();

const show = defineModel<boolean>('show', { default: false });
const startDate = defineModel<string>('startDate', { required: true });
const endDate = defineModel<string>('endDate', { required: true });

const emit = defineEmits<{
    export: [];
}>();
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>Export detailed PDF report</template>
        <template #content>
            <div class="space-y-5 text-text-primary">
                <p class="text-sm text-text-secondary">{{ project?.name }}</p>
                <div class="space-y-2">
                    <div class="text-sm font-medium">Report period</div>
                    <DateRangePicker v-model:start="startDate" v-model:end="endDate" />
                </div>
            </div>
        </template>
        <template #footer>
            <SecondaryButton :disabled="loading" @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton class="ms-3" :loading="loading" @click="emit('export')">
                Generate PDF
            </PrimaryButton>
        </template>
    </DialogModal>
</template>
