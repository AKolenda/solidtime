<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Requests\V1\Project\ProjectStoreRequest;
use App\Http\Requests\V1\Task\TaskStoreRequest;
use App\Http\Requests\V1\TimeEntry\TimeEntryStoreRequest;
use App\Http\Requests\V1\TimeEntry\TimeEntryUpdateRequest;
use App\Http\Resources\V1\TimeEntry\TimeEntryResource;
use App\Models\Organization;
use App\Models\TimeEntry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Durable replay for creations and time entry updates whose authorization has already been checked. */
class ApiCreationIdempotency
{
    /**
     * @param  Closure(array<string, mixed>): JsonResource  $create  receives the validated input
     */
    public function execute(ProjectStoreRequest|TaskStoreRequest|TimeEntryStoreRequest $request, Organization $organization, string $resource, Closure $create): JsonResource|JsonResponse
    {
        $key = $this->key($request);
        if ($key === null) {
            return $create($request->validated());
        }

        $scope = $this->scope($request, $organization, $resource, $key);
        $payloadHash = $this->payloadHash($request);

        return DB::transaction(function () use ($request, $organization, $scope, $payloadHash, $create): JsonResponse {
            // This existing row provides a cross-process lock, including the first request
            // before an operation row exists. The creation and replay record commit together.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $replay = $this->replay($scope, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            // A client recovering a request whose answer it lost asks only for the earlier result
            if ($request->hasHeader('Idempotency-Replay-Only')) {
                abort(404, 'Nothing was created with this Idempotency-Key.', ['X-Solidtime-Idempotency' => 'none']);
            }

            $validated = Validator::make($request->all(), $request->creationRules())->validate();
            $response = $create($validated)->response();
            $this->record($scope, $organization, $payloadHash, $response);

            return $response->header('X-Solidtime-Idempotency', 'v1');
        });
    }

    /**
     * Update one time entry under its row lock. With an Idempotency-Key, a retry replays the
     * first response. With If-Match, the update runs only while the entry still has that ETag,
     * so a change made by someone else in between is refused (412) instead of overwritten.
     *
     * @param  Closure(TimeEntry, array<string, mixed>): JsonResource  $update  receives the locked entry and the validated input
     */
    public function executeUpdate(TimeEntryUpdateRequest $request, Organization $organization, TimeEntry $timeEntry, Closure $update): JsonResource|JsonResponse
    {
        $key = $this->key($request);
        $ifMatch = $request->header('If-Match');
        if ($key === null && $ifMatch === null) {
            return $update($timeEntry, $request->validated());
        }
        $scope = $key === null ? null : $this->scope($request, $organization, 'time-entries:'.$timeEntry->getKey(), $key);
        $payloadHash = $this->payloadHash($request);

        return DB::transaction(function () use ($request, $organization, $timeEntry, $update, $scope, $payloadHash, $ifMatch): JsonResponse {
            /** @var TimeEntry $locked */
            $locked = TimeEntry::query()->whereKey($timeEntry->getKey())->lockForUpdate()->firstOrFail();
            if ($scope !== null) {
                $replay = $this->replay($scope, $payloadHash);
                if ($replay !== null) {
                    // The ETag of the entry as that first update left it
                    $data = $replay->getData(true)['data'] ?? null;

                    return is_array($data) ? $replay->header('ETag', '"'.TimeEntryResource::etagOf($data).'"') : $replay;
                }
            }
            if ($ifMatch !== null && ! hash_equals(TimeEntryResource::etag($locked), trim(str_replace('W/', '', $ifMatch), " \t\""))) {
                abort(412, 'The time entry changed since it was read.');
            }
            $validated = $scope !== null ? Validator::make($request->all(), $request->updateRules($locked))->validate() : $request->validated();
            $response = $update($locked, $validated)->response();
            if ($scope !== null) {
                $this->record($scope, $organization, $payloadHash, $response);
                $response->header('X-Solidtime-Idempotency', 'v1');
            }

            return $response->header('ETag', '"'.TimeEntryResource::etag($locked).'"');
        });
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use 16 to 128 letters, numbers, colons, underscores or hyphens.']);
        }

        return $key;
    }

    private function scope(Request $request, Organization $organization, string $resource, string $key): string
    {
        return hash('sha256', json_encode([$organization->id, $request->user()?->getAuthIdentifier(), $resource, $key], JSON_THROW_ON_ERROR));
    }

    private function payloadHash(Request $request): string
    {
        return hash('sha256', json_encode($this->canonicalize($request->all()), JSON_THROW_ON_ERROR));
    }

    /**
     * The first response for this key, or null on its first use. A key used again with another
     * body is refused; its header tells the client that the key's first request succeeded.
     */
    private function replay(string $scope, string $payloadHash): ?JsonResponse
    {
        $operation = DB::table('api_creation_operations')->where('scope_key', $scope)->first();
        if ($operation === null) {
            return null;
        }
        if ($operation->payload_hash !== $payloadHash) {
            abort(409, 'Idempotency-Key was already used with a different payload.', ['X-Solidtime-Idempotency' => 'mismatch']);
        }

        return response()->json(json_decode($operation->response_json, true, 512, JSON_THROW_ON_ERROR), $operation->response_status)
            ->header('X-Solidtime-Idempotency', 'v1');
    }

    private function record(string $scope, Organization $organization, string $payloadHash, JsonResponse $response): void
    {
        DB::table('api_creation_operations')->insert([
            'scope_key' => $scope,
            'organization_id' => $organization->id,
            'payload_hash' => $payloadHash,
            'response_json' => $response->getContent(),
            'response_status' => $response->getStatusCode(),
            'created_at' => now(),
        ]);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
