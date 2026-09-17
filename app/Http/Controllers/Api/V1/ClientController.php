<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\EntityStillInUseApiException;
use App\Http\Requests\V1\Client\ClientIndexRequest;
use App\Http\Requests\V1\Client\ClientMergeIntoRequest;
use App\Http\Requests\V1\Client\ClientStoreRequest;
use App\Http\Requests\V1\Client\ClientUpdateRequest;
use App\Http\Resources\V1\Client\ClientCollection;
use App\Http\Resources\V1\Client\ClientResource;
use App\Models\Client;
use App\Models\Organization;
use App\Service\ClientService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ClientController extends Controller
{
    protected function checkPermission(Organization $organization, string $permission, ?Client $client = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($client !== null && $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Client does not belong to organization');
        }
    }

    /**
     * Get clients
     *
     * @return ClientCollection<ClientResource>
     *
     * @throws AuthorizationException
     *
     * @operationId getClients
     */
    public function index(Organization $organization, ClientIndexRequest $request): ClientCollection
    {
        $this->checkPermission($organization, 'clients:view');
        $canViewAllClients = $this->hasPermission($organization, 'clients:view:all');
        $user = $this->user();

        $clientsQuery = Client::query()
            ->whereBelongsTo($organization, 'organization')
            ->withMax('projects', 'created_at')
            ->orderBy('created_at', 'desc')
            ->orderBy('id');

        if (! $canViewAllClients) {
            $clientsQuery->visibleByEmployee($user);
        }

        $filterArchived = $request->getFilterArchived();
        if ($filterArchived === 'true') {
            $clientsQuery->whereNotNull('archived_at');
        } elseif ($filterArchived === 'false') {
            $clientsQuery->whereNull('archived_at');
        }

        $clients = $clientsQuery->paginate(config('app.pagination_per_page_default'));

        return new ClientCollection($clients);
    }

    /**
     * Create client
     *
     * @throws AuthorizationException
     *
     * @operationId createClient
     */
    public function store(Organization $organization, ClientStoreRequest $request): ClientResource
    {
        $this->checkPermission($organization, 'clients:create');

        $client = new Client;
        $client->name = $request->input('name');
        $client->organization()->associate($organization);
        $client->save();

        return new ClientResource($client);
    }

    /**
     * Update client
     *
     * @throws AuthorizationException
     *
     * @operationId updateClient
     */
    public function update(Organization $organization, Client $client, ClientUpdateRequest $request): ClientResource
    {
        $this->checkPermission($organization, 'clients:update', $client);

        $client->name = $request->input('name');
        if ($request->has('is_archived')) {
            $client->archived_at = $request->getIsArchived() ? Carbon::now() : null;
        }
        if ($request->has('is_closed')) {
            $client->reopened_at = $request->getIsClosed() ? null : Carbon::now();
        }
        $client->save();

        return new ClientResource($client);
    }

    /**
     * Delete client
     *
     * @throws AuthorizationException|EntityStillInUseApiException
     *
     * @operationId deleteClient
     */
    public function destroy(Organization $organization, Client $client): JsonResponse
    {
        $this->checkPermission($organization, 'clients:delete', $client);

        if ($client->projects()->exists()) {
            throw new EntityStillInUseApiException('client', 'project');
        }

        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Merge one client into another
     *
     * Projects of the source client are moved onto the destination client.
     * The destination keeps whichever of the two names is selected, and the source is deleted.
     *
     * @throws AuthorizationException
     *
     * @operationId mergeClient
     */
    public function mergeInto(Organization $organization, Client $client, ClientMergeIntoRequest $request, ClientService $clientService): JsonResponse
    {
        $this->checkPermission($organization, 'clients:update', $client);

        $destination = Client::findOrFail($request->getClientId());
        $clientService->mergeInto($organization, $client, $destination, $request->getNameClientId());

        return response()->json(null, 204);
    }
}
