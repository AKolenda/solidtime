<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\Requests\V1\Project\ProjectStoreRequest;
use App\Http\Requests\V1\Task\TaskStoreRequest;
use App\Models\Organization;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Durable replay for creations whose authorization has already been checked. */
class ApiCreationIdempotency
{
    public function execute(ProjectStoreRequest|TaskStoreRequest $request, Organization $organization, string $resource, Closure $create): JsonResource|JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null) {
            return $create();
        }
        if (! preg_match('/\A[A-Za-z0-9:_-]{16,128}\z/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use 16 to 128 letters, numbers, colons, underscores or hyphens.']);
        }

        $scope = hash('sha256', json_encode([$organization->id, $request->user()->getAuthIdentifier(), $resource, $key], JSON_THROW_ON_ERROR));
        $payloadHash = hash('sha256', json_encode($this->canonicalize($request->all()), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $organization, $scope, $payloadHash, $create): JsonResponse {
            // This existing row provides a cross-process lock, including the first request
            // before an operation row exists. The creation and replay record commit together.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $operation = DB::table('api_creation_operations')->where('scope_key', $scope)->first();
            if ($operation !== null) {
                abort_if($operation->payload_hash !== $payloadHash, 409, 'Idempotency-Key was already used with a different payload.');

                return response()->json(json_decode($operation->response_json, true, 512, JSON_THROW_ON_ERROR), $operation->response_status)
                    ->header('X-Solidtime-Idempotency', 'v1');
            }

            Validator::make($request->all(), $request->creationRules())->validate();
            $response = $create()->response();
            DB::table('api_creation_operations')->insert([
                'scope_key' => $scope,
                'organization_id' => $organization->id,
                'payload_hash' => $payloadHash,
                'response_json' => $response->getContent(),
                'response_status' => $response->getStatusCode(),
                'created_at' => now(),
            ]);

            return $response->header('X-Solidtime-Idempotency', 'v1');
        });
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
