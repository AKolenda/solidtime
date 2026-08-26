<x-filament-panels::page>
    @php($latest = $this->getLatestRun())
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            <x-filament::button type="submit">Save backup settings</x-filament::button>
        </form>
        <aside class="space-y-4">
            <x-filament::section heading="Backup status">
                @if($latest)
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt>Status</dt><dd class="font-semibold">{{ ucfirst($latest->status) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Started</dt><dd>{{ $latest->started_at?->diffForHumans() }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Next run</dt><dd>{{ $this->getNextScheduledRun()?->format('M j, H:i T') ?? 'Disabled' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Duration</dt><dd>{{ $latest->finished_at ? $latest->started_at->diffForHumans($latest->finished_at, true) : 'In progress' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Validated</dt><dd>{{ $latest->validated ? 'Yes' : 'No' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>File</dt><dd class="max-w-48 break-all text-right">{{ $latest->filename ?? '-' }}</dd></div>
                        <div class="flex justify-between gap-4"><dt>Size</dt><dd>{{ $latest->size_bytes ? number_format($latest->size_bytes / 1048576, 2).' MB' : '-' }}</dd></div>
                        @if($latest->error)<div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-950 dark:text-danger-300">{{ $latest->error }}</div>@endif
                    </dl>
                @else
                    <p class="text-sm text-gray-500">No backup has run yet.</p>
                    <p class="mt-2 text-sm">Next run: {{ $this->getNextScheduledRun()?->format('M j, H:i T') ?? 'Disabled' }}</p>
                @endif
            </x-filament::section>
            <x-filament::section heading="Recent runs">
                <div class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @forelse($this->getRecentRuns() as $run)
                        <div class="py-3 first:pt-0 last:pb-0">
                            <div class="flex justify-between gap-3"><span class="font-medium">{{ ucfirst($run->status) }}</span><span>{{ $run->started_at?->format('M j, H:i') }}</span></div>
                            <div class="mt-1 text-xs text-gray-500">{{ $run->filename ?? $run->error ?? 'In progress' }}</div>
                        </div>
                    @empty
                        <p class="text-gray-500">No history yet.</p>
                    @endforelse
                </div>
            </x-filament::section>
        </aside>
    </div>
</x-filament-panels::page>
