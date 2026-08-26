<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Common/Card.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import Checkbox from '@/packages/ui/src/Input/Checkbox.vue';
import InputError from '@/packages/ui/src/Input/InputError.vue';
import InputLabel from '@/packages/ui/src/Input/InputLabel.vue';
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/packages/ui/src';
import { CircleStackIcon } from '@heroicons/vue/16/solid';
import { DocumentArrowUpIcon } from '@heroicons/vue/24/outline';
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

type Settings = {
    enabled: boolean;
    root_path: string;
    subdirectory: string;
    time: string;
    timezone: string;
    retention_days: number;
    output_format: 'dump' | 'sql' | 'both';
};

type BackupRun = {
    id: number;
    status: string;
    filename: string | null;
    size_bytes: number | null;
    validated: boolean;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
};

const props = defineProps<{ settings: Settings; timezones: string[]; runs: BackupRun[] }>();
const form = useForm({ ...props.settings });
const restoreForm = useForm<{ backup: File | null; confirmation: string }>({
    backup: null,
    confirmation: '',
});
const restoreFilename = computed(() => restoreForm.backup?.name ?? 'Choose a backup file');

function save() {
    form.put(route('database-backups.update'), { preserveScroll: true });
}

function selectRestoreFile(event: Event) {
    restoreForm.backup = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function restore() {
    restoreForm.post(route('database-backups.restore'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => restoreForm.reset(),
    });
}

function formatSize(bytes: number | null) {
    return bytes === null ? '—' : `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

function formatDate(value: string | null) {
    return value === null
        ? '—'
        : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(
              new Date(value)
          );
}
</script>

<template>
    <AppLayout title="Database Backups" data-testid="database_backups_view">
        <MainContainer class="py-5 border-b border-default-background-separator flex items-center">
            <PageTitle :icon="CircleStackIcon" title="Database Backups" />
        </MainContainer>

        <MainContainer class="py-6">
            <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
                <section>
                    <div class="mb-2 text-sm font-medium">Backup schedule</div>
                    <Card>
                        <form class="p-5 space-y-5" @submit.prevent="save">
                            <label class="flex items-start gap-3">
                                <Checkbox
                                    id="backup-enabled"
                                    v-model:checked="form.enabled"
                                    class="mt-0.5" />
                                <span>
                                    <span class="block text-sm font-medium"
                                        >Enable daily backups</span
                                    >
                                    <span class="block text-sm text-text-secondary"
                                        >The scheduler creates one verified PostgreSQL backup each
                                        day.</span
                                    >
                                </span>
                            </label>

                            <div class="grid gap-5 md:grid-cols-2">
                                <div class="space-y-1.5 md:col-span-2">
                                    <InputLabel for="root-path" value="Backup destination" />
                                    <TextInput
                                        id="root-path"
                                        v-model="form.root_path"
                                        class="w-full" />
                                    <p class="text-sm text-text-secondary">
                                        Use an absolute path already mounted and writable inside the
                                        container, such as /backups. Changing this field does not
                                        create a Docker or TrueNAS mount.
                                    </p>
                                    <InputError :message="form.errors.root_path" />
                                </div>

                                <div class="space-y-1.5">
                                    <InputLabel for="subdirectory" value="Subfolder (optional)" />
                                    <TextInput
                                        id="subdirectory"
                                        v-model="form.subdirectory"
                                        class="w-full"
                                        placeholder="solidtime" />
                                    <InputError :message="form.errors.subdirectory" />
                                </div>

                                <div class="space-y-1.5">
                                    <InputLabel for="retention" value="Keep backups for (days)" />
                                    <TextInput
                                        id="retention"
                                        v-model="form.retention_days"
                                        type="number"
                                        min="1"
                                        max="3650"
                                        class="w-full" />
                                    <InputError :message="form.errors.retention_days" />
                                </div>

                                <div class="space-y-1.5">
                                    <InputLabel for="backup-time" value="Daily backup time" />
                                    <TextInput
                                        id="backup-time"
                                        v-model="form.time"
                                        type="time"
                                        class="w-full" />
                                    <InputError :message="form.errors.time" />
                                </div>

                                <div class="space-y-1.5">
                                    <InputLabel for="timezone" value="Timezone" />
                                    <Select v-model="form.timezone">
                                        <SelectTrigger id="timezone" class="w-full">
                                            <SelectValue placeholder="Select a timezone" />
                                        </SelectTrigger>
                                        <SelectContent class="max-h-72">
                                            <SelectItem
                                                v-for="timezone in timezones"
                                                :key="timezone"
                                                :value="timezone"
                                                >{{ timezone }}</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                    <InputError :message="form.errors.timezone" />
                                </div>

                                <div class="space-y-1.5">
                                    <InputLabel for="output-format" value="Backup format" />
                                    <Select v-model="form.output_format">
                                        <SelectTrigger id="output-format" class="w-full">
                                            <SelectValue placeholder="Select a format" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="dump"
                                                >PostgreSQL archive (.dump)</SelectItem
                                            >
                                            <SelectItem value="sql">Readable SQL (.sql)</SelectItem>
                                            <SelectItem value="both"
                                                >Both .dump and .sql</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                    <p class="text-sm text-text-secondary">
                                        Use .dump with pg_restore, or .sql with psql. “Both” keeps
                                        both versions.
                                    </p>
                                    <InputError :message="form.errors.output_format" />
                                </div>
                            </div>

                            <div
                                class="flex justify-end border-t border-default-background-separator pt-4">
                                <PrimaryButton :loading="form.processing" type="submit"
                                    >Save backup settings</PrimaryButton
                                >
                            </div>
                        </form>
                    </Card>
                </section>

                <section class="space-y-6">
                    <div>
                        <div class="mb-2 text-sm font-medium">Backup status</div>
                        <Card class="p-5">
                            <template v-if="runs[0]">
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                    <dt class="text-text-secondary">Status</dt>
                                    <dd class="text-right font-medium capitalize">
                                        {{ runs[0].status }}
                                    </dd>
                                    <dt class="text-text-secondary">Started</dt>
                                    <dd class="text-right">{{ formatDate(runs[0].started_at) }}</dd>
                                    <dt class="text-text-secondary">Validated</dt>
                                    <dd class="text-right">
                                        {{ runs[0].validated ? 'Yes' : 'No' }}
                                    </dd>
                                    <dt class="text-text-secondary">File</dt>
                                    <dd class="text-right break-all">
                                        {{ runs[0].filename ?? '—' }}
                                    </dd>
                                    <dt class="text-text-secondary">Size</dt>
                                    <dd class="text-right">{{ formatSize(runs[0].size_bytes) }}</dd>
                                </dl>
                                <p v-if="runs[0].error" class="mt-4 text-sm text-red-400">
                                    {{ runs[0].error }}
                                </p>
                            </template>
                            <p v-else class="text-sm text-text-secondary">
                                No backups have run yet.
                            </p>
                        </Card>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-medium">Recent runs</div>
                        <Card class="divide-y divide-default-background-separator">
                            <div v-for="run in runs" :key="run.id" class="p-4 text-sm">
                                <div class="flex justify-between gap-4">
                                    <span class="font-medium capitalize">{{ run.status }}</span
                                    ><span class="text-text-secondary">{{
                                        formatDate(run.started_at)
                                    }}</span>
                                </div>
                                <div class="mt-1 text-text-secondary break-all">
                                    {{ run.filename ?? run.error ?? 'No file created' }}
                                </div>
                            </div>
                            <div v-if="runs.length === 0" class="p-4 text-sm text-text-secondary">
                                No backup history yet.
                            </div>
                        </Card>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-medium">Restore database</div>
                        <Card>
                            <form class="p-5 space-y-4" @submit.prevent="restore">
                                <p class="text-sm text-text-secondary">
                                    Replaces the active database from a PostgreSQL .dump or .sql
                                    file. A safety backup is created first.
                                </p>
                                <label
                                    for="restore-file"
                                    class="flex cursor-pointer flex-col items-center rounded-lg border border-dashed border-border-primary px-5 py-7 text-center hover:bg-secondary">
                                    <DocumentArrowUpIcon class="h-8 w-8 text-text-secondary" />
                                    <span class="mt-3 text-sm font-semibold text-text-primary">{{
                                        restoreFilename
                                    }}</span>
                                    <span class="mt-1 text-xs text-text-secondary"
                                        >PostgreSQL .dump or .sql, up to 512 MB</span
                                    >
                                    <input
                                        id="restore-file"
                                        type="file"
                                        accept=".dump,.sql"
                                        class="sr-only"
                                        @change="selectRestoreFile" />
                                </label>
                                <InputError :message="restoreForm.errors.backup" />
                                <div class="space-y-1.5">
                                    <InputLabel
                                        for="restore-confirmation"
                                        value="Type RESTORE to confirm" />
                                    <TextInput
                                        id="restore-confirmation"
                                        v-model="restoreForm.confirmation"
                                        class="w-full"
                                        autocomplete="off" />
                                    <InputError :message="restoreForm.errors.confirmation" />
                                </div>
                                <div class="flex justify-end">
                                    <PrimaryButton
                                        type="submit"
                                        :loading="restoreForm.processing"
                                        :disabled="
                                            restoreForm.backup === null ||
                                            restoreForm.confirmation !== 'RESTORE'
                                        ">
                                        Restore database
                                    </PrimaryButton>
                                </div>
                            </form>
                        </Card>
                    </div>
                </section>
            </div>
        </MainContainer>
    </AppLayout>
</template>
