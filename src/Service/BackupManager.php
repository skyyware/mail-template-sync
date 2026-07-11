<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Skyyware\SkyyMailTemplateSync\Storage\TemplateStorageInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final class BackupManager
{
    private const TIMESTAMP_FORMAT = 'Ymd\THis\Z';

    private readonly ClockInterface $clock;

    private readonly Filesystem $filesystem;

    /** @var Closure(string, string): void */
    private readonly Closure $promote;

    /** @param null|Closure(string, string): void $promote */
    public function __construct(
        private readonly TemplateStorageInterface $storage,
        private readonly string $projectRoot,
        private readonly SystemConfigService $systemConfig,
        ?ClockInterface $clock = null,
        ?Closure $promote = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->filesystem = new Filesystem();
        $this->promote = $promote ?? static function (string $temporaryRoot, string $backupRoot): void {
            if (!@rename($temporaryRoot, $backupRoot)) {
                throw new SyncException(sprintf(
                    'Unable to promote temporary backup "%s" to "%s".',
                    $temporaryRoot,
                    $backupRoot,
                ));
            }
        };
    }

    public function backup(MailTemplate $template): string
    {
        $retentionDays = $this->retentionDays();
        $backupDirectory = $this->backupDirectory();
        $this->filesystem->mkdir($backupDirectory);

        do {
            $sessionName = sprintf('%s-%s', $this->now()->format(self::TIMESTAMP_FORMAT), bin2hex(random_bytes(8)));
            $backupRoot = $backupDirectory . '/' . $sessionName;
            $temporaryRoot = $backupDirectory . '/.tmp-' . $sessionName;
        } while (file_exists($backupRoot) || file_exists($temporaryRoot));

        $this->filesystem->mkdir($temporaryRoot);

        try {
            $this->storage->write($template, $temporaryRoot);
            ($this->promote)($temporaryRoot, $backupRoot);
        } catch (Throwable $exception) {
            $this->filesystem->remove($temporaryRoot);

            throw $exception;
        }

        $this->cleanupExcept($backupRoot, $retentionDays);

        return $backupRoot;
    }

    public function cleanup(): void
    {
        $this->cleanupExcept(null, $this->retentionDays());
    }

    public function validateConfiguration(): void
    {
        $this->retentionDays();
    }

    private function cleanupExcept(?string $excludedBackupRoot, int $retentionDays): void
    {
        $backupDirectory = $this->backupDirectory();
        if (!is_dir($backupDirectory)) {
            return;
        }

        $cutoff = $this->now()->modify(sprintf('-%d days', $retentionDays));
        foreach (glob($backupDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            if ($directory === $excludedBackupRoot) {
                continue;
            }

            $timestamp = $this->parseTimestamp(basename($directory));

            if ($timestamp !== null && $timestamp < $cutoff) {
                $this->filesystem->remove($directory);
            }
        }
    }

    private function backupDirectory(): string
    {
        return rtrim($this->projectRoot, '/\\') . '/var/skyy-mail-template-sync/backups';
    }

    private function retentionDays(): int
    {
        $retentionDays = $this->systemConfig->getInt('SkyyMailTemplateSync.config.backupRetentionDays');
        if ($retentionDays < 0) {
            throw new InvalidArgumentException('Backup retention days must not be negative.');
        }

        return $retentionDays;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseTimestamp(string $directoryName): ?DateTimeImmutable
    {
        if (preg_match('/^(?<timestamp>\d{8}T\d{6}Z)(?:-[0-9a-f]{16})?$/D', $directoryName, $matches) !== 1) {
            return null;
        }

        $timestamp = DateTimeImmutable::createFromFormat(
            '!' . self::TIMESTAMP_FORMAT,
            $matches['timestamp'],
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false || $errors !== false || $timestamp->format(self::TIMESTAMP_FORMAT) !== $matches['timestamp']) {
            return null;
        }

        return $timestamp;
    }
}
