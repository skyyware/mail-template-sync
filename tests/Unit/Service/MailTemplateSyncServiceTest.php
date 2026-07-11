<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Repository\MailTemplateRepositoryInterface;
use Skyyware\SkyyMailTemplateSync\Service\BackupManager;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateDiffer;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateSyncService;
use Skyyware\SkyyMailTemplateSync\Storage\FilesystemTemplateStorage;
use Skyyware\SkyyMailTemplateSync\Storage\TemplateStorageInterface;
use Symfony\Component\Clock\MockClock;

final class MailTemplateSyncServiceTest extends TestCase
{
    private Context $context;

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $path = tempnam(sys_get_temp_dir(), 'skyy-mail-template-sync-service-');
        self::assertNotFalse($path);
        unlink($path);
        mkdir($path);
        $this->projectRoot = $path;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testExportsOneSelectedTemplate(): void
    {
        $repository = new RecordingRepository(['first' => $this->template('first', 'Subject')]);
        $storage = new RecordingStorage();
        $service = $this->service($repository, $storage, $this->successfulConnection(0));

        $result = $service->export('first', false, '/exports', $this->context);

        self::assertSame(['first'], $repository->fetches);
        self::assertSame([['first', '/exports']], $storage->writes);
        self::assertSame(['first'], $result->processedNames);
    }

    public function testExportsAllTemplatesInRepositoryOrder(): void
    {
        $repository = new RecordingRepository([
            'first' => $this->template('first', 'First'),
            'second' => $this->template('second', 'Second'),
        ]);
        $storage = new RecordingStorage();
        $service = $this->service($repository, $storage, $this->successfulConnection(0));

        $result = $service->export(null, true, '/exports', $this->context);

        self::assertSame(['first', 'second'], $repository->fetches);
        self::assertSame([['first', '/exports'], ['second', '/exports']], $storage->writes);
        self::assertSame(['first', 'second'], $result->processedNames);
    }

    public function testExportCreatesMissingRootWithoutStrictDiscovery(): void
    {
        $repository = new RecordingRepository(['first' => $this->template('first', 'Subject')]);
        $storage = new FilesystemTemplateStorage();
        $exportRoot = $this->projectRoot . '/missing-export-root';

        $result = $this->service($repository, $storage, $this->successfulConnection(0))
            ->export('first', false, $exportRoot, $this->context);

        self::assertSame(['first'], $result->processedNames);
        self::assertFileExists($exportRoot . '/first/en-GB/subject.twig');
    }

    public function testImportAllRejectsEmptyDiscoveryResult(): void
    {
        $repository = new RecordingRepository([]);
        $storage = new RecordingStorage([], []);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No mail template bundles were found in the storage directory.');

        $this->service($repository, $storage, $connection)
            ->import(null, true, '/imports', false, $this->context);
    }

    public function testExportAuthoritativelyReportsAndRemovesFilesystemOnlyLocales(): void
    {
        $storage = new FilesystemTemplateStorage();
        $exportRoot = $this->projectRoot . '/exports';
        $storage->write(new MailTemplate('first', [
            new MailTemplateTranslation('de-DE', 'Skyy Shop', 'Deutsch', 'Beschreibung', '<p>Inhalt</p>', 'Inhalt'),
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'English', 'Description', '<p>Body</p>', 'Body'),
        ]), $exportRoot);
        $repository = new RecordingRepository(['first' => $this->template('first', 'English')]);

        $result = $this->service($repository, $storage, $this->successfulConnection(0))
            ->export('first', false, $exportRoot, $this->context);

        self::assertSame(['first' => ['translations.de-DE']], $result->changedFields);
        self::assertFileDoesNotExist($exportRoot . '/first/translations/de-DE.json');
        self::assertSame(
            ['en-GB'],
            array_column($storage->read('first', $exportRoot)->translations, 'locale'),
        );
    }

    public function testValidatesEveryImportBeforeAnyWrite(): void
    {
        $events = new EventLog();
        $repository = new RecordingRepository([
            'first' => $this->template('first', 'Current'),
            'second' => $this->template('second', 'Current'),
        ], $events);
        $storage = new RecordingStorage([
            'first' => $this->template('first', 'Changed'),
            'second' => new RuntimeException('Invalid second import'),
        ], ['first', 'second'], $events);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid second import');

        try {
            $this->service($repository, $storage, $connection)->import(null, true, '/imports', false, $this->context);
        } finally {
            self::assertSame(['read:first', 'read:second'], $events->events);
            self::assertSame([], $repository->updates);
            self::assertSame([], $storage->writes);
        }
    }

    public function testDryRunReportsRedactedDiffWithoutBackupOrUpdate(): void
    {
        $current = $this->template('first', 'Old subject', '<p>old secret</p>');
        $incoming = $this->template('first', 'New subject', '<p>new secret</p>');
        $repository = new RecordingRepository(['first' => $current]);
        $storage = new RecordingStorage(['first' => $incoming]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $result = $this->service($repository, $storage, $connection)
            ->import('first', false, '/imports', true, $this->context);

        self::assertSame([], $repository->updates);
        self::assertSame([], $storage->writes);
        self::assertSame(['first'], $result->processedNames);
        self::assertSame([
            'first' => ['translations.en-GB.contentHtml', 'translations.en-GB.subject'],
        ], $result->changedFields);
        self::assertStringNotContainsString('secret', serialize($result));
    }

    public function testDryRunDiffDistinguishesNullFromEmptyString(): void
    {
        $current = new MailTemplate('first', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Subject', null, '<p>Body</p>', 'Body'),
        ]);
        $incoming = new MailTemplate('first', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Subject', '', '<p>Body</p>', 'Body'),
        ]);
        $repository = new RecordingRepository(['first' => $current]);
        $storage = new RecordingStorage(['first' => $incoming]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $result = $this->service($repository, $storage, $connection)
            ->import('first', false, '/imports', true, $this->context);

        self::assertSame([
            'first' => ['translations.en-GB.description'],
        ], $result->changedFields);
    }

    public function testValidatesEveryDatabaseTargetBeforeAnyWrite(): void
    {
        $events = new EventLog();
        $repository = new RecordingRepository([
            'first' => $this->template('first', 'Current'),
            'second' => $this->template('second', 'Current'),
        ], $events);
        $repository->fetchFailures['second'] = new RuntimeException('Missing second target');
        $storage = new RecordingStorage([
            'first' => $this->template('first', 'Changed'),
            'second' => $this->template('second', 'Changed'),
        ], ['first', 'second'], $events);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing second target');

        try {
            $this->service($repository, $storage, $connection)->import(null, true, '/imports', false, $this->context);
        } finally {
            self::assertSame(['read:first', 'read:second', 'fetch:first', 'fetch:second'], $events->events);
            self::assertSame([], $repository->updates);
            self::assertSame([], $storage->writes);
        }
    }

    public function testInvalidLocaleInSecondAllModeTemplateHasNoWriteSideEffects(): void
    {
        $events = new EventLog();
        $repository = new RecordingRepository([
            'first' => $this->template('first', 'Current first'),
            'second' => $this->template('second', 'Current second'),
        ], $events);
        $repository->validationFailures['second'] = new RuntimeException(
            'No Shopware language exists for locale "fr-FR".',
        );
        $storage = new RecordingStorage([
            'first' => $this->template('first', 'Changed first'),
            'second' => $this->templateForLocale('second', 'fr-FR', 'Changed second'),
        ], ['first', 'second'], $events);
        $oldBackup = $this->projectRoot . '/var/skyy-mail-template-sync/backups/20200101T000000Z';
        mkdir($oldBackup, 0777, true);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Shopware language exists for locale "fr-FR".');

        try {
            $this->service($repository, $storage, $connection)->import(null, true, '/imports', false, $this->context);
        } finally {
            self::assertSame([
                'read:first',
                'read:second',
                'fetch:first',
                'fetch:second',
                'validate:first',
                'validate:second',
            ], $events->events);
            self::assertSame(['first', 'second'], $repository->validations);
            self::assertSame([], $repository->updates);
            self::assertSame([], $storage->writes);
            self::assertDirectoryExists($oldBackup, 'Retention cleanup must not run during failed preflight.');
        }
    }

    public function testTargetOnlyLocalesAreUntouchedAndNotReportedAsChanges(): void
    {
        $current = new MailTemplate('first', [
            new MailTemplateTranslation('de-DE', 'Skyy Shop', 'Deutsch', 'Beschreibung', '<p>Inhalt</p>', 'Inhalt'),
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'English', 'Description', '<p>Body</p>', 'Body'),
        ]);
        $incoming = $this->template('first', 'English');
        $repository = new RecordingRepository(['first' => $current]);
        $storage = new RecordingStorage(['first' => $incoming]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $result = $this->service($repository, $storage, $connection)
            ->import('first', false, '/imports', false, $this->context);

        self::assertSame([], $result->changedFields);
        self::assertSame([], $repository->updates);
        self::assertSame([], $storage->writes);
    }

    public function testBacksUpBeforeUpdateAndUsesOneTransactionPerChangedTemplate(): void
    {
        $events = new EventLog();
        $repository = new RecordingRepository([
            'first' => $this->template('first', 'Old first'),
            'second' => $this->template('second', 'Old second'),
        ], $events);
        $storage = new RecordingStorage([
            'first' => $this->template('first', 'New first'),
            'second' => $this->template('second', 'New second'),
        ], ['first', 'second'], $events);
        $connection = $this->successfulConnection(2);

        $result = $this->service($repository, $storage, $connection)
            ->import(null, true, '/imports', false, $this->context);

        self::assertSame([
            'read:first',
            'read:second',
            'fetch:first',
            'fetch:second',
            'validate:first',
            'validate:second',
            'lock:first',
            'fetch:first',
            'backup:first',
            'update:first',
            'lock:second',
            'fetch:second',
            'backup:second',
            'update:second',
        ], $events->events);
        self::assertSame(['first', 'second'], $result->processedNames);
    }

    public function testRefetchesInsideTransactionAndBacksUpConcurrentAdminState(): void
    {
        $events = new EventLog();
        $preflight = new MailTemplate('first', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Preflight subject', 'Incoming description', '<p>Body</p>', 'Body'),
        ]);
        $adminEdit = new MailTemplate('first', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Incoming subject', 'Admin description', '<p>Body</p>', 'Body'),
        ]);
        $incoming = new MailTemplate('first', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Incoming subject', 'Incoming description', '<p>Body</p>', 'Body'),
        ]);
        $repository = new RecordingRepository(['first' => $preflight], $events);
        $repository->fetchSequences['first'] = [$preflight, $adminEdit];
        $storage = new RecordingStorage(['first' => $incoming], [], $events);

        $result = $this->service($repository, $storage, $this->successfulConnection(1))
            ->import('first', false, '/imports', false, $this->context);

        self::assertSame([
            'read:first',
            'fetch:first',
            'validate:first',
            'lock:first',
            'fetch:first',
            'backup:first',
            'update:first',
        ], $events->events);
        self::assertSame('Admin description', $storage->backups[0]->translations[0]->description);
        self::assertSame([
            'first' => ['translations.en-GB.description'],
        ], $result->changedFields);
    }

    public function testSkipsBackupAndUpdateWhenTransactionRefetchAlreadyMatchesIncoming(): void
    {
        $events = new EventLog();
        $preflight = $this->template('first', 'Preflight subject');
        $incoming = $this->template('first', 'Incoming subject');
        $repository = new RecordingRepository(['first' => $preflight], $events);
        $repository->fetchSequences['first'] = [$preflight, $incoming];
        $storage = new RecordingStorage(['first' => $incoming], [], $events);

        $result = $this->service($repository, $storage, $this->successfulConnection(1))
            ->import('first', false, '/imports', false, $this->context);

        self::assertSame(['read:first', 'fetch:first', 'validate:first', 'lock:first', 'fetch:first'], $events->events);
        self::assertSame([], $storage->backups);
        self::assertSame([], $repository->updates);
        self::assertSame([], $result->changedFields);
    }

    public function testNegativeRetentionFailsBeforeTransactionOrBackup(): void
    {
        $repository = new RecordingRepository(['first' => $this->template('first', 'Old')]);
        $storage = new RecordingStorage(['first' => $this->template('first', 'New')]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup retention days must not be negative.');

        try {
            $this->service($repository, $storage, $connection, -1)
                ->import('first', false, '/imports', false, $this->context);
        } finally {
            self::assertSame([], $storage->backups);
            self::assertSame([], $repository->updates);
        }
    }

    public function testRollsBackTransactionWhenUpdateFails(): void
    {
        $events = new EventLog();
        $repository = new RecordingRepository(['first' => $this->template('first', 'Old')], $events);
        $repository->updateFailure = new RuntimeException('DAL update failed');
        $storage = new RecordingStorage(['first' => $this->template('first', 'New')], [], $events);

        /** @var Connection&MockObject $connection */
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['beginTransaction', 'commit', 'rollBack'])
            ->getMock();
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::never())->method('commit');
        $connection->expects(self::once())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DAL update failed');

        try {
            $this->service($repository, $storage, $connection)
                ->import('first', false, '/imports', false, $this->context);
        } finally {
            self::assertSame([
                'read:first',
                'fetch:first',
                'validate:first',
                'lock:first',
                'fetch:first',
                'backup:first',
                'update:first',
            ], $events->events);
        }
    }

    private function service(
        RecordingRepository $repository,
        TemplateStorageInterface $storage,
        Connection $connection,
        int $retentionDays = 365,
    ): MailTemplateSyncService {
        return new MailTemplateSyncService(
            $repository,
            $storage,
            new MailTemplateDiffer(),
            new BackupManager($storage, $this->projectRoot, $this->retention($retentionDays), new MockClock('2026-07-11 12:00:00 UTC')),
            $connection,
        );
    }

    private function retention(int $days = 365): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn($days);

        return $systemConfig;
    }

    private function successfulConnection(int $transactionCount): Connection
    {
        /** @var Connection&MockObject $connection */
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['beginTransaction', 'commit', 'rollBack'])
            ->getMock();
        $connection->expects(self::exactly($transactionCount))->method('beginTransaction');
        $connection->expects(self::exactly($transactionCount))->method('commit');
        $connection->expects(self::never())->method('rollBack');

        return $connection;
    }

    private function template(string $technicalName, string $subject, string $html = '<p>Body</p>'): MailTemplate
    {
        return new MailTemplate($technicalName, [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', $subject, 'Description', $html, 'Body'),
        ]);
    }

    private function templateForLocale(string $technicalName, string $locale, string $subject): MailTemplate
    {
        return new MailTemplate($technicalName, [
            new MailTemplateTranslation($locale, 'Skyy Shop', $subject, 'Description', '<p>Body</p>', 'Body'),
        ]);
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

final class RecordingRepository implements MailTemplateRepositoryInterface
{
    /** @var list<string> */
    public array $fetches = [];

    /** @var list<string> */
    public array $locks = [];

    /** @var list<string> */
    public array $updates = [];

    /** @var list<string> */
    public array $validations = [];

    public ?RuntimeException $updateFailure = null;

    /** @var array<string, RuntimeException> */
    public array $fetchFailures = [];

    /** @var array<string, RuntimeException> */
    public array $validationFailures = [];

    /** @var array<string, list<MailTemplate>> */
    public array $fetchSequences = [];

    /** @param array<string, MailTemplate> $templates */
    public function __construct(private readonly array $templates, private readonly ?EventLog $events = null) {}

    public function listTechnicalNames(Context $context): array
    {
        return array_keys($this->templates);
    }

    public function lockSystemDefault(string $technicalName): void
    {
        $this->locks[] = $technicalName;
        $this->events?->record('lock:' . $technicalName);
    }

    public function fetch(string $technicalName, Context $context): MailTemplate
    {
        $this->fetches[] = $technicalName;
        $this->events?->record('fetch:' . $technicalName);

        if (isset($this->fetchFailures[$technicalName])) {
            throw $this->fetchFailures[$technicalName];
        }

        if (($this->fetchSequences[$technicalName] ?? []) !== []) {
            $template = array_shift($this->fetchSequences[$technicalName]);
            TestCase::assertInstanceOf(MailTemplate::class, $template);

            return $template;
        }

        return $this->templates[$technicalName];
    }

    public function validate(MailTemplate $template, Context $context): void
    {
        $this->validations[] = $template->technicalName;
        $this->events?->record('validate:' . $template->technicalName);

        if (isset($this->validationFailures[$template->technicalName])) {
            throw $this->validationFailures[$template->technicalName];
        }
    }

    public function update(MailTemplate $template, Context $context): void
    {
        $this->updates[] = $template->technicalName;
        $this->events?->record('update:' . $template->technicalName);

        if ($this->updateFailure !== null) {
            throw $this->updateFailure;
        }
    }
}

final class RecordingStorage implements TemplateStorageInterface
{
    /** @var list<MailTemplate> */
    public array $backups = [];

    /** @var list<array{string, string}> */
    public array $writes = [];

    /**
     * @param array<string, MailTemplate|RuntimeException> $templates
     * @param list<string> $discovered
     */
    public function __construct(
        private readonly array $templates = [],
        private readonly array $discovered = [],
        private readonly ?EventLog $events = null,
    ) {}

    public function read(string $technicalName, string $root): MailTemplate
    {
        $this->events?->record('read:' . $technicalName);
        $template = $this->templates[$technicalName];

        if ($template instanceof RuntimeException) {
            throw $template;
        }

        return $template;
    }

    public function exists(string $technicalName, string $root): bool
    {
        return isset($this->templates[$technicalName]);
    }

    public function write(MailTemplate $template, string $root): void
    {
        $this->writes[] = [$template->technicalName, $root];

        if (str_contains($root, '/var/skyy-mail-template-sync/backups/')) {
            $this->backups[] = $template;
            $this->events?->record('backup:' . $template->technicalName);
        }
    }

    public function discover(string $root): array
    {
        return $this->discovered;
    }
}

final class EventLog
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}
