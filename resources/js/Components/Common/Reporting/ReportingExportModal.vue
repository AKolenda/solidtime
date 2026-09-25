<script setup lang="ts">
import { ArrowDownTrayIcon, CheckCircleIcon, EyeIcon, XMarkIcon } from '@heroicons/vue/20/solid';
import { Modal, PrimaryButton, SecondaryButton } from '@/packages/ui/src';
const props = defineProps<{
    exportUrl: string | null;
    previewUrl: string | null;
}>();

const showExportModal = defineModel('show', { default: false });

function downloadCurrentExport() {
    if (props.exportUrl) {
        window.open(props.exportUrl, '_self');
    }
}

function previewCurrentExport() {
    if (props.previewUrl) {
        window.open(props.previewUrl, '_blank');
    }
}
</script>

<template>
    <Modal
        closeable
        max-width="lg"
        overlay-class="backdrop-blur-none"
        :show="showExportModal"
        @close="showExportModal = false">
        <div class="relative text-center text-text-primary py-6">
            <button
                aria-label="Close"
                class="text-text-tertiary w-6 mx-auto absolute focus-visible:outline-none focus-visible:ring-2 rounded-full focus-visible:ring-ring transition focus-visible:text-text-primary hover:text-text-primary top-2 right-2"
                @click="showExportModal = false">
                <XMarkIcon></XMarkIcon>
            </button>
            <div class="flex items-center font-semibold text-lg justify-center space-x-2 pb-5">
                <CheckCircleIcon class="text-text-tertiary w-6"></CheckCircleIcon>
                <span> Export Successful! </span>
            </div>
            <div class="flex justify-center gap-2">
                <SecondaryButton v-if="previewUrl" :icon="EyeIcon" @click="previewCurrentExport"
                    >Preview</SecondaryButton
                >
                <PrimaryButton :icon="ArrowDownTrayIcon" @click="downloadCurrentExport"
                    >Download</PrimaryButton
                >
            </div>
        </div>
    </Modal>
</template>

<style scoped></style>
