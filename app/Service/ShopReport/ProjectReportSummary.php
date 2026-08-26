<?php

declare(strict_types=1);

namespace App\Service\ShopReport;

use App\Models\TimeEntry;
use Illuminate\Support\Collection;

final readonly class ProjectReportSummary
{
    /**
     * @param  list<array{part: string, quantity: float|null, turning: float|null, milling: float|null}>  $parts
     * @param  list<array{name: string, seconds: int, seconds_per_piece: float|null}>  $taskTotals
     * @param  list<array{name: string, setup_seconds: int, running_seconds: int, seconds_per_piece: float|null}>  $operations
     */
    public function __construct(
        public string $projectName,
        public ?string $purchaseOrder,
        public array $parts,
        public array $taskTotals,
        public array $operations,
        public ?int $runningSeconds,
        public ?float $totalQuantity,
    ) {}

    /** @param Collection<int, TimeEntry> $timeEntries */
    public static function from(string $projectName, Collection $timeEntries): self
    {
        $segments = array_values(array_filter(array_map('trim', explode(' - ', $projectName))));
        $partNames = isset($segments[0]) ? array_map('trim', explode('+', $segments[0])) : [];
        $purchaseOrder = $segments[1] ?? null;
        $quantities = self::numberList(self::segmentValue($segments, '/(?:pcs?|pieces?|halves)\b/i'));
        $turning = self::numberList(self::prefixedValue($segments, 'QT'));
        $milling = self::numberList(self::prefixedValue($segments, 'QM'));

        $parts = [];
        foreach ($partNames as $index => $partName) {
            $parts[] = [
                'part' => $partName,
                'quantity' => $quantities[$index] ?? (count($quantities) === 1 ? $quantities[0] : null),
                'turning' => $turning[$index] ?? (count($turning) === 1 ? $turning[0] : null),
                'milling' => $milling[$index] ?? (count($milling) === 1 ? $milling[0] : null),
            ];
        }

        $totalQuantity = array_sum(array_filter(array_column($parts, 'quantity'), fn ($value): bool => $value !== null));
        $taskTotals = $timeEntries
            ->groupBy(fn (TimeEntry $entry): string => $entry->task?->name ?? 'No task')
            ->map(function (Collection $entries, string $name) use ($totalQuantity): array {
                $seconds = (int) $entries->sum(fn (TimeEntry $entry): int => (int) $entry->getDuration()->totalSeconds);
                $isRunning = str_contains(strtolower($name), 'running');

                return [
                    'name' => $name,
                    'seconds' => $seconds,
                    'seconds_per_piece' => $isRunning && $totalQuantity > 0 ? $seconds / $totalQuantity : null,
                ];
            })
            ->sortBy(function (array $task): string {
                $name = strtolower($task['name']);
                $operationOrder = str_starts_with($name, 'turning') ? 0 : (str_starts_with($name, 'milling') ? 1 : 2);
                $taskOrder = str_contains($name, 'running') ? 1 : 0;

                return sprintf('%d-%d-%s', $operationOrder, $taskOrder, $name);
            })
            ->values()
            ->all();

        $runningSeconds = (int) $timeEntries
            ->filter(fn (TimeEntry $entry): bool => str_contains(strtolower($entry->task?->name ?? ''), 'running'))
            ->sum(fn (TimeEntry $entry): int => (int) $entry->getDuration()->totalSeconds);
        $operations = collect(['Turning', 'Milling'])->map(function (string $operation) use ($timeEntries, $totalQuantity): array {
            $matching = $timeEntries->filter(fn (TimeEntry $entry): bool => str_contains(strtolower($entry->task?->name ?? ''), strtolower($operation)));
            $running = (int) $matching
                ->filter(fn (TimeEntry $entry): bool => str_contains(strtolower($entry->task?->name ?? ''), 'running'))
                ->sum(fn (TimeEntry $entry): int => (int) $entry->getDuration()->totalSeconds);
            $setup = (int) $matching
                ->reject(fn (TimeEntry $entry): bool => str_contains(strtolower($entry->task?->name ?? ''), 'running'))
                ->sum(fn (TimeEntry $entry): int => (int) $entry->getDuration()->totalSeconds);

            return [
                'name' => $operation,
                'setup_seconds' => $setup,
                'running_seconds' => $running,
                'seconds_per_piece' => $running > 0 && $totalQuantity > 0 ? $running / $totalQuantity : null,
            ];
        })->filter(fn (array $operation): bool => $operation['setup_seconds'] > 0 || $operation['running_seconds'] > 0)->values()->all();

        return new self(
            projectName: $projectName,
            purchaseOrder: $purchaseOrder,
            parts: $parts,
            taskTotals: $taskTotals,
            operations: $operations,
            runningSeconds: $runningSeconds > 0 ? $runningSeconds : null,
            totalQuantity: $totalQuantity > 0 ? $totalQuantity : null,
        );
    }

    /** @param list<string> $segments */
    private static function segmentValue(array $segments, string $pattern): ?string
    {
        foreach ($segments as $segment) {
            if (preg_match($pattern, $segment) === 1) {
                return $segment;
            }
        }

        return null;
    }

    /** @param list<string> $segments */
    private static function prefixedValue(array $segments, string $prefix): ?string
    {
        foreach ($segments as $segment) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\s*(.+)$/i', trim($segment), $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @return list<float> */
    private static function numberList(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        preg_match_all('/\d+(?:\.\d+)?/', $value, $matches);

        return array_map('floatval', $matches[0]);
    }
}
