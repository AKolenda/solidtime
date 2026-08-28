<?php

declare(strict_types=1);

namespace Tests\Unit\Service\SelfHost;

use App\Service\SelfHost\DatabaseBackupMountDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(DatabaseBackupMountDiscovery::class)]
class DatabaseBackupMountDiscoveryTest extends TestCase
{
    public function test_it_lists_writable_mounts_and_existing_folders_and_recommends_the_configured_folder(): void
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'solidtime-mount-discovery-'.bin2hex(random_bytes(6));
        $mount = $base.DIRECTORY_SEPARATOR.'Mounted Backups';
        $destination = $mount.DIRECTORY_SEPARATOR.'solidtime_backups';
        $mountInfo = $base.DIRECTORY_SEPARATOR.'mountinfo';
        mkdir($destination, 0777, true);
        file_put_contents(
            $mountInfo,
            '42 31 0:50 /host/backups '.str_replace(' ', '\\040', $mount).' rw,relatime - ext4 /dev/sda rw'.PHP_EOL
        );
        config([
            'database-backup.mountinfo_path' => $mountInfo,
            'database-backup.container_path' => '/backups',
            'database-backup.discovery_prefixes' => [$mount],
        ]);

        try {
            $directories = app(DatabaseBackupMountDiscovery::class)->discover($destination);

            $this->assertSame($destination, $directories[0]['path']);
            $this->assertSame($mount, $directories[0]['mount_path']);
            $this->assertTrue($directories[0]['recommended']);
            $this->assertContains($mount, array_column($directories, 'path'));
        } finally {
            @unlink($mountInfo);
            @rmdir($destination);
            @rmdir($mount);
            @rmdir($base);
        }
    }

    public function test_it_does_not_offer_system_mounts(): void
    {
        $mountInfo = tempnam(sys_get_temp_dir(), 'solidtime-mountinfo-');
        $this->assertIsString($mountInfo);
        file_put_contents($mountInfo, '42 31 0:50 / /etc rw,relatime - ext4 /dev/sda rw'.PHP_EOL);
        config([
            'database-backup.mountinfo_path' => $mountInfo,
            'database-backup.container_path' => '/backups',
            'database-backup.discovery_prefixes' => ['/backups', '/mnt', '/data'],
        ]);

        try {
            $this->assertSame([], app(DatabaseBackupMountDiscovery::class)->discover('/backups'));
        } finally {
            @unlink($mountInfo);
        }
    }
}
