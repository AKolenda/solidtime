<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

class TimeEntryGuardedUpdateEndpointTest extends ApiEndpointTestAbstract
{
    private function entry(object $data): TimeEntry
    {
        return TimeEntry::factory()->forMember($data->member)->create([
            'start' => Carbon::parse('2026-10-01 08:00:00'), 'end' => null, 'description' => 'Turning',
        ]);
    }

    public function test_if_match_applies_only_to_the_entry_that_was_read(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:own', 'time-entries:update:own']);
        $entry = $this->entry($data);
        Passport::actingAs($data->user);
        $etag = $this->getJson(route('api.v1.time-entries.show', [$data->organization->id, $entry->id]))->assertOk()->headers->get('ETag');
        $url = route('api.v1.time-entries.update', [$data->organization->id, $entry->id]);

        $stopped = $this->putJson($url, ['end' => '2026-10-01T10:00:00Z'], ['If-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $stopped->headers->get('ETag'));
        // A second writer that read the running timer is refused instead of moving its end
        $this->putJson($url, ['end' => '2026-10-01T11:00:00Z'], ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('2026-10-01 10:00:00', $entry->refresh()->end->toDateTimeString());
        $this->putJson($url, ['description' => 'Turning OP10'], ['If-Match' => $stopped->headers->get('ETag')])->assertOk();
    }

    public function test_keyed_update_replays_and_flags_a_reused_key(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:own', 'time-entries:update:own']);
        $entry = $this->entry($data);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.update', [$data->organization->id, $entry->id]);
        $headers = ['Idempotency-Key' => 'erp-stop-0000000001'];

        $first = $this->putJson($url, ['end' => '2026-10-01T10:00:00Z'], $headers)->assertOk()->assertHeader('X-Solidtime-Idempotency', 'v1');
        $entry->update(['description' => 'Changed by hand']);
        $retry = $this->putJson($url, ['end' => '2026-10-01T10:00:00Z'], $headers)->assertOk()->assertHeader('X-Solidtime-Idempotency', 'v1');
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame('Changed by hand', $entry->refresh()->description);
        $this->putJson($url, ['end' => '2026-10-01T10:30:00Z'], $headers)->assertConflict()->assertHeader('X-Solidtime-Idempotency', 'mismatch');
        $this->assertSame('2026-10-01 10:00:00', $entry->refresh()->end->toDateTimeString());
    }

    public function test_guarded_update_still_checks_permission(): void
    {
        $data = $this->createUserWithPermission(['time-entries:view:all', 'time-entries:update:own']);
        $foreign = $this->entry((object) ['member' => $data->ownerMember]);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.update', [$data->organization->id, $foreign->id]);
        $etag = $this->getJson(route('api.v1.time-entries.show', [$data->organization->id, $foreign->id]))->assertOk()->headers->get('ETag');

        $this->putJson($url, ['end' => '2026-10-01T10:00:00Z'], ['If-Match' => $etag, 'Idempotency-Key' => 'erp-stop-0000000002'])->assertForbidden();
        $this->assertNull($foreign->refresh()->end);
        $this->assertDatabaseCount('api_creation_operations', 0);
    }

    public function test_keyed_timer_may_leave_out_start_and_replays_it(): void
    {
        $data = $this->createUserWithPermission(['time-entries:create:own']);
        Passport::actingAs($data->user);
        $url = route('api.v1.time-entries.store-idempotent', [$data->organization->id]);
        $payload = ['member_id' => $data->member->id, 'billable' => false, 'end' => null];

        Carbon::setTestNow('2026-10-21 14:00:00.500');
        $first = $this->postJson($url, $payload, ['Idempotency-Key' => 'erp-timer-0000000001'])->assertCreated();
        $this->assertSame('2026-10-21T14:00:00Z', $first->json('data.start'));
        Carbon::setTestNow('2026-10-21 14:05:00');
        $this->assertSame($first->json(), $this->postJson($url, $payload, ['Idempotency-Key' => 'erp-timer-0000000001'])->assertCreated()->json());
        $this->assertDatabaseCount('time_entries', 1);

        // Without a key, or for a finished entry, start stays required
        $this->postJson(route('api.v1.time-entries.store', [$data->organization->id]), $payload)->assertJsonValidationErrorFor('start');
        $this->postJson($url, [...$payload, 'end' => '2026-10-21T15:00:00Z'], ['Idempotency-Key' => 'erp-entry-0000000001'])->assertJsonValidationErrorFor('start');
        Carbon::setTestNow();
    }
}
