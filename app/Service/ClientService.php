<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientService
{
    /**
     * Move the source client's projects onto the destination client, apply the chosen
     * name, rewrite saved reports that filtered on the source client, and delete the source.
     */
    public function mergeInto(Organization $organization, Client $source, Client $destination, string $nameClientId): void
    {
        if ($source->organization_id !== $organization->getKey()) {
            throw new InvalidArgumentException('Source client does not belong to organization');
        }
        if ($destination->organization_id !== $organization->getKey()) {
            throw new InvalidArgumentException('Destination client does not belong to organization');
        }
        if ($source->getKey() === $destination->getKey()) {
            throw new InvalidArgumentException('Cannot merge a client into itself');
        }
        if ($nameClientId !== $source->getKey() && $nameClientId !== $destination->getKey()) {
            throw new InvalidArgumentException('Name client must be one of the merged clients');
        }

        DB::transaction(function () use ($organization, $source, $destination, $nameClientId): void {
            Project::query()
                ->whereBelongsTo($organization, 'organization')
                ->where('client_id', $source->getKey())
                ->update(['client_id' => $destination->getKey()]);

            $this->rewriteReportClientIds($organization, $source->getKey(), $destination->getKey());

            if ($nameClientId === $source->getKey()) {
                $destination->name = $source->name;
                $destination->save();
            }

            $source->delete();
        });
    }

    private function rewriteReportClientIds(Organization $organization, string $sourceId, string $destinationId): void
    {
        $reports = Report::query()
            ->whereBelongsTo($organization, 'organization')
            ->get();

        foreach ($reports as $report) {
            $clientIds = $report->properties->clientIds;
            if ($clientIds === null || ! $clientIds->contains($sourceId)) {
                continue;
            }

            $updated = $clientIds
                ->map(fn (string $id): string => $id === $sourceId ? $destinationId : $id)
                ->unique()
                ->values()
                ->all();

            $properties = $report->properties;
            $properties->setClientIds($updated);
            $report->properties = $properties;
            $report->save();
        }
    }
}
