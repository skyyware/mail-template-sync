<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Service\BackupManager;
use Skyyware\SkyyMailTemplateSync\Storage\FilesystemTemplateStorage;
use Skyyware\SkyyMailTemplateSync\Storage\TemplateStorageInterface;
use Symfony\Component\Clock\MockClock;

final class BackupManagerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'skyy-mail-template-backups-');
        self::assertNotFalse($path);
        unlink($path);
        mkdir($path);
        $this->projectRoot = $path;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testWritesPortableBackupUnderUtcTimestamp(): void
    {
        $storage = new FilesystemTemplateStorage();
        $manager = new BackupManager($storage, $this->projectRoot, $this->retention(365), new MockClock('2026-07-11 12:34:56 UTC'));

        $backupRoot = $manager->backup($this->template());

        self::assertMatchesRegularExpression(
            '#/var/skyy-mail-template-sync/backups/20260711T123456Z-[0-9a-f]{16}$#',
            $backupRoot,
        );
        self::assertEquals($this->template(), $storage->read('order_confirmation_mail', $backupRoot));
        self::assertSame([], glob(dirname($backupRoot) . '/.tmp-*') ?: []);
    }

    public function testCreatesUniqueBackupRootsForMultipleImportsInOneSecond(): void
    {
        $storage = new FilesystemTemplateStorage();
        $manager = new BackupManager($storage, $this->projectRoot, $this->retention(365), new MockClock('2026-07-11 12:34:56 UTC'));

        $firstRoot = $manager->backup($this->template());
        $secondRoot = $manager->backup($this->template());

        self::assertNotSame($firstRoot, $secondRoot);
        self::assertDirectoryExists($firstRoot);
        self::assertDirectoryExists($secondRoot);
        self::assertEquals($this->template(), $storage->read('order_confirmation_mail', $firstRoot));
        self::assertEquals($this->template(), $storage->read('order_confirmation_mail', $secondRoot));
    }

    public function testFailedBackupRemovesTemporaryContentAndPreservesExistingBackups(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        $oldBackup = $base . '/20250601T000000Z';
        mkdir($oldBackup, 0777, true);
        file_put_contents($oldBackup . '/existing-backup', 'keep');
        $manager = new BackupManager(
            new PartiallyFailingStorage(),
            $this->projectRoot,
            $this->retention(1),
            new MockClock('2026-07-11 12:34:56 UTC'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup write failed');

        try {
            $manager->backup($this->template());
        } finally {
            self::assertFileExists($oldBackup . '/existing-backup');
            self::assertSame([], glob($base . '/.tmp-*') ?: []);
            self::assertSame([$oldBackup], glob($base . '/*', GLOB_ONLYDIR) ?: []);
        }
    }

    public function testFailedNativePromotionRemovesTemporaryContentAndPreservesPriorBackup(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        $oldBackup = $base . '/20250601T000000Z';
        mkdir($oldBackup, 0777, true);
        file_put_contents($oldBackup . '/existing-backup', 'keep');
        $promotionPaths = [];
        $promote = static function (string $temporaryRoot, string $backupRoot) use (&$promotionPaths): void {
            $promotionPaths = [$temporaryRoot, $backupRoot];

            throw new RuntimeException('Native backup promotion failed');
        };
        $manager = new BackupManager(
            new FilesystemTemplateStorage(),
            $this->projectRoot,
            $this->retention(1),
            new MockClock('2026-07-11 12:34:56 UTC'),
            $promote,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Native backup promotion failed');

        try {
            $manager->backup($this->template());
        } finally {
            self::assertCount(2, $promotionPaths);
            self::assertSame(dirname($promotionPaths[0]), dirname($promotionPaths[1]));
            self::assertSame('.tmp-' . basename($promotionPaths[1]), basename($promotionPaths[0]));
            self::assertFileExists($oldBackup . '/existing-backup');
            self::assertSame([], glob($base . '/.tmp-*') ?: []);
            self::assertSame([$oldBackup], glob($base . '/*', GLOB_ONLYDIR) ?: []);
        }
    }

    public function testZeroDayRetentionNeverDeletesJustPromotedBackup(): void
    {
        $storage = new FilesystemTemplateStorage();
        $manager = new BackupManager(
            $storage,
            $this->projectRoot,
            $this->retention(0),
            new MockClock('2026-07-11 12:34:56.654321 UTC'),
        );

        $backupRoot = $manager->backup($this->template());

        self::assertDirectoryExists($backupRoot);
        self::assertEquals($this->template(), $storage->read('order_confirmation_mail', $backupRoot));
    }

    public function testNegativeRetentionRejectsBeforeCreatingBackup(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        $manager = new BackupManager(
            new FilesystemTemplateStorage(),
            $this->projectRoot,
            $this->retention(-1),
            new MockClock('2026-07-11 12:34:56 UTC'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup retention days must not be negative.');

        try {
            $manager->backup($this->template());
        } finally {
            self::assertDirectoryDoesNotExist($base);
        }
    }

    public function testNegativeRetentionRejectsBeforePruningExistingBackup(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        $oldBackup = $base . '/20200101T000000Z';
        mkdir($oldBackup, 0777, true);
        $manager = new BackupManager(
            new FilesystemTemplateStorage(),
            $this->projectRoot,
            $this->retention(-1),
            new MockClock('2026-07-11 12:34:56 UTC'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup retention days must not be negative.');

        try {
            $manager->cleanup();
        } finally {
            self::assertDirectoryExists($oldBackup);
        }
    }

    public function testDeletesOnlyTimestampedBackupsOlderThanRetention(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        mkdir($base, 0777, true);
        mkdir($base . '/20260701T115959Z');
        mkdir($base . '/20260701T115959Z-0123456789abcdef');
        mkdir($base . '/20260701T120000Z-fedcba9876543210');
        mkdir($base . '/keep-me');
        $manager = new BackupManager(
            new FilesystemTemplateStorage(),
            $this->projectRoot,
            $this->retention(10),
            new MockClock('2026-07-11 12:00:00 UTC'),
        );

        $manager->cleanup();

        self::assertDirectoryDoesNotExist($base . '/20260701T115959Z');
        self::assertDirectoryDoesNotExist($base . '/20260701T115959Z-0123456789abcdef');
        self::assertDirectoryExists($base . '/20260701T120000Z-fedcba9876543210');
        self::assertDirectoryExists($base . '/keep-me');
    }

    public function testRetentionIgnoresNormalizedInvalidTimestampDates(): void
    {
        $base = $this->projectRoot . '/var/skyy-mail-template-sync/backups';
        $invalidDate = $base . '/20260230T120000Z';
        mkdir($invalidDate, 0777, true);
        $manager = new BackupManager(
            new FilesystemTemplateStorage(),
            $this->projectRoot,
            $this->retention(1),
            new MockClock('2026-07-11 12:00:00 UTC'),
        );

        $manager->cleanup();

        self::assertDirectoryExists($invalidDate);
    }

    private function template(): MailTemplate
    {
        return new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Subject', 'Description', '<p>Body</p>', 'Body'),
        ]);
    }

    private function retention(int $days): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')
            ->with('SkyyMailTemplateSync.config.backupRetentionDays')
            ->willReturn($days);

        return $systemConfig;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertNotFalse($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}

final class PartiallyFailingStorage implements TemplateStorageInterface
{
    public function exists(string $technicalName, string $root): bool
    {
        return false;
    }

    public function read(string $technicalName, string $root): MailTemplate
    {
        throw new RuntimeException('Not used.');
    }

    public function write(MailTemplate $template, string $root): void
    {
        mkdir($root . '/' . $template->technicalName, 0777, true);
        file_put_contents($root . '/' . $template->technicalName . '/partial', 'partial');

        throw new RuntimeException('Backup write failed');
    }

    public function discover(string $root): array
    {
        return [];
    }
}
