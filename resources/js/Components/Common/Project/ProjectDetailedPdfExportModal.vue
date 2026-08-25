<script setup lang="ts">
import type { Project } from '@/packages/api/src';
import DateRangePicker from '@/packages/ui/src/Input/DateRangePicker.vue';
import { Modal, PrimaryButton, SecondaryButton } from '@/packages/ui/src';

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
    <Modal closeable max-width="lg" :show="show" @close="show = false">
        <div class="p-6 space-y-5 text-text-primary">
            <div>
                <h2 class="text-lg font-semibold">Export detailed PDF report</h2>
                <p class="mt-1 text-sm text-text-secondary">
                    {{ project?.name }}
                </p>
            </div>

            <div class="space-y-2">
                <div class="text-sm font-medium">Report period</div>
                <DateRangePicker v-model:start="startDate" v-model:end="endDate" />
            </div>

            <div class="flex justify-end gap-3">
                <SecondaryButton :disabled="loading" @click="show = false">Cancel</SecondaryButton>
                <PrimaryButton :loading="loading" @click="emit('export')">
                    Generate PDF
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
