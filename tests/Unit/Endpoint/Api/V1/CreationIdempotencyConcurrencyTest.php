<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\Role;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CreationIdempotencyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_http_requests_commit_one_project_and_replay_the_same_response(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Cross-process verification requires pcntl.');
        }
        $user = User::factory()->create();
        $organization = Organization::factory()->withOwner($user)->create();
        Member::factory()->forUser($user)->forOrganization($organization)->create(['role' => Role::Owner->value]);
        Passport::actingAs($user);
        $url = route('api.v1.projects.store-idempotent', [$organization->id]);
        $payload = ['name' => 'Concurrent create', 'color' => '#112233', 'is_billable' => false, 'client_id' => null];
        $files = [tempnam(sys_get_temp_dir(), 'idempotency-'), tempnam(sys_get_temp_dir(), 'idempotency-')];
        // The delay holds the row lock while the second worker reaches creation.
        Project::creating(static function (): void {
            usleep(300000);
        });
        DB::disconnect();
        $children = [];
        foreach ($files as $file) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $response = $this->postJson($url, $payload, ['Idempotency-Key' => 'concurrent-project-0001']);
                    file_put_contents($file, json_encode(['status' => $response->status(), 'body' => $response->json()], JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $error) {
                    file_put_contents($file, $error->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        try {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $first = json_decode(file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
            $second = json_decode(file_get_contents($files[1]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(201, $first['status']);
            $this->assertSame($first, $second);
            $this->assertDatabaseCount('projects', 1);
            $this->assertDatabaseCount('api_creation_operations', 1);
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
