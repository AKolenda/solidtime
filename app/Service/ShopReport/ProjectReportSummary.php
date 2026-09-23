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
        public ?float $secondsPerPiece,
    ) {}

    /** @param Collection<int, TimeEntry> $timeEntries */
    public static function from(string $projectName, Collection $timeEntries): self
    {
        $segments = array_values(array_filter(array_map('trim', explode(' - ', $projectName))));
        $partNames = isset($segments[0]) ? array_map('trim', explode('+', $segments[0])) : [];
        $purchaseOrder = $segments[1] ?? null;
        $quantities = self::numberList(self::segmentValue($segments, '/\d.*(?:pcs?|pieces?|halves)\b(?!\/)/i'));
        // One part ordered on several purchase orders lists a quantity per order, e.g. "28 + 6 Pcs".
        if (count($partNames) === 1 && count($quantities) > 1) {
            $quantities = [(float) array_sum($quantities)];
        }
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

        $partQuantities = array_filter(array_column($parts, 'quantity'), fn ($value): bool => $value !== null && $value > 0);
        $totalQuantity = (float) array_sum($partQuantities);
        // The first piece of every part is covered by programming, setup and F/O, so running time only covers the rest.
        $runningQuantity = $totalQuantity - count($partQuantities);
        $runningPerPiece = fn (int $seconds): ?float => $seconds > 0 && $runningQuantity > 0 ? $seconds / $runningQuantity : null;
        // Without running entries the first-off cannot be separated, so the whole time is spread over every piece.
        $totalPerPiece = fn (int $seconds): ?float => $seconds > 0 && $totalQuantity > 0 ? $seconds / $totalQuantity : null;

        $durationOf = fn (Collection $entries): int => (int) $entries->sum(fn (TimeEntry $entry): int => (int) $entry->getDuration()->totalSeconds);
        $isRunning = fn (?string $taskName): bool => str_contains(strtolower($taskName ?? ''), 'running');
        $operationOf = fn (?string $taskName): string => strtolower(trim(explode(' - ', $taskName ?? '', 2)[0]));
        $operationsWithRunning = $timeEntries
            ->filter(fn (TimeEntry $entry): bool => $isRunning($entry->task?->name))
            ->map(fn (TimeEntry $entry): string => $operationOf($entry->task?->name))
            ->unique()
            ->all();

        $taskTotals = $timeEntries
            ->groupBy(fn (TimeEntry $entry): string => $entry->task?->name ?? 'No task')
            ->map(function (Collection $entries, string $name) use ($durationOf, $isRunning, $operationOf, $operationsWithRunning, $runningPerPiece, $totalPerPiece): array {
                $seconds = $durationOf($entries);
                $taskName = $entries->first()?->task?->name;

                return [
                    'name' => $name,
                    'seconds' => $seconds,
                    'seconds_per_piece' => match (true) {
                        $isRunning($taskName) => $runningPerPiece($seconds),
                        ! in_array($operationOf($taskName), $operationsWithRunning, true) => $totalPerPiece($seconds),
                        default => null,
                    },
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

        $runningSeconds = $durationOf($timeEntries->filter(fn (TimeEntry $entry): bool => $isRunning($entry->task?->name)));
        $operations = collect(['Turning', 'Milling'])->map(function (string $operation) use ($timeEntries, $durationOf, $isRunning, $runningPerPiece, $totalPerPiece): array {
            $matching = $timeEntries->filter(fn (TimeEntry $entry): bool => str_contains(strtolower($entry->task?->name ?? ''), strtolower($operation)));
            $running = $durationOf($matching->filter(fn (TimeEntry $entry): bool => $isRunning($entry->task?->name)));
            $setup = $durationOf($matching->reject(fn (TimeEntry $entry): bool => $isRunning($entry->task?->name)));

            return [
                'name' => $operation,
                'setup_seconds' => $setup,
                'running_seconds' => $running,
                'seconds_per_piece' => $running > 0 ? $runningPerPiece($running) : $totalPerPiece($setup),
            ];
        })->filter(fn (array $operation): bool => $operation['setup_seconds'] > 0 || $operation['running_seconds'] > 0)->values()->all();

        $operationAverages = array_filter(array_column($operations, 'seconds_per_piece'), fn (?float $value): bool => $value !== null);
        $secondsPerPiece = $operationAverages !== []
            ? (float) array_sum($operationAverages)
            : $totalPerPiece($durationOf($timeEntries));

        return new self(
            projectName: $projectName,
            purchaseOrder: $purchaseOrder,
            parts: $parts,
            taskTotals: $taskTotals,
            operations: $operations,
            runningSeconds: $runningSeconds > 0 ? $runningSeconds : null,
            totalQuantity: $totalQuantity > 0 ? $totalQuantity : null,
            secondsPerPiece: $secondsPerPiece,
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
