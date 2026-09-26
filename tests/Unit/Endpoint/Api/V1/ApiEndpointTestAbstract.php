<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCaseWithDatabase;

class ApiEndpointTestAbstract extends TestCaseWithDatabase
{
    protected function assertResponseCode(TestResponse $response, int $statusCode): void
    {
        if ($response->getStatusCode() !== $statusCode) {
            dump($response->getContent());
        }
        $response->assertStatus($statusCode);
    }

    /**
     * Captures the options passed to temporaryUrl on the private disk.
     * Returns a closure that yields the options of every call in order.
     *
     * @return Closure(): array<int, array<string, mixed>>
     */
    protected function captureTemporaryUrlOptions(): Closure
    {
        $captured = [];
        $diskName = config('filesystems.private');
        $disk = Mockery::mock(Storage::disk($diskName))->makePartial();
        $disk->shouldReceive('temporaryUrl')->andReturnUsing(
            function (string $path, DateTimeInterface $expiration, array $options) use (&$captured): string {
                $captured[] = $options;

                return 'https://storage.fake/'.$path;
            }
        );
        Storage::set($diskName, $disk);

        return function () use (&$captured): array {
            return $captured;
        };
    }
}
