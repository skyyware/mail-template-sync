<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use Error;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Command\ExportMailTemplatesCommand;
use Skyyware\SkyyMailTemplateSync\Command\ImportMailTemplatesCommand;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Skyyware\SkyyMailTemplateSync\Repository\MailTemplateRepositoryInterface;
use Skyyware\SkyyMailTemplateSync\Service\BackupManager;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateDiffer;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateSyncService;
use Skyyware\SkyyMailTemplateSync\Storage\StorageDirectoryResolver;
use Skyyware\SkyyMailTemplateSync\Storage\TemplateStorageInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

final class MailTemplateCommandsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'skyy-mail-template-command-');
        self::assertNotFalse($path);
        unlink($path);
        mkdir($path);
        $this->projectRoot = $path;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testExportRequiresExactlyOneSelector(): void
    {
        $tester = $this->exportTester(new CommandRepository(), new CommandStorage());

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Select exactly one technical name or use --all.', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['technical-name' => 'order', '--all' => true]));
        self::assertStringContainsString('Select exactly one technical name or use --all.', $tester->getDisplay());
    }

    public function testExportAllUsesResolvedDirectoryAndPrintsOnlyRedactedSummary(): void
    {
        $repository = new CommandRepository([
            'first' => $this->template('first', 'First subject', '<p>first secret body</p>'),
            'second' => $this->template('second', 'Second subject', '<p>second secret body</p>'),
        ]);
        $storage = new CommandStorage();
        $tester = $this->exportTester($repository, $storage);

        $status = $tester->execute(['--all' => true, '--directory' => 'exports/mail']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([
            ['first', $this->projectRoot . '/exports/mail'],
            ['second', $this->projectRoot . '/exports/mail'],
        ], $storage->writes);
        self::assertStringContainsString('Export complete: 2 processed, 2 changed.', $tester->getDisplay());
        self::assertStringContainsString('first: changed (translations.en-GB', $tester->getDisplay());
        self::assertStringContainsString('second: changed (translations.en-GB', $tester->getDisplay());
        self::assertStringNotContainsString('secret body', $tester->getDisplay());
        self::assertStringNotContainsString('First subject', $tester->getDisplay());
    }

    public function testExportPrintsChangedOrUnchangedForEveryProcessedTemplate(): void
    {
        $first = $this->template('first', 'Unchanged');
        $repository = new CommandRepository([
            'first' => $first,
            'second' => $this->template('second', 'New subject'),
        ]);
        $storage = new CommandStorage([
            'first' => $first,
            'second' => $this->template('second', 'Old subject'),
        ], ['first', 'second']);
        $tester = $this->exportTester($repository, $storage);

        $status = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('first: unchanged', $tester->getDisplay());
        self::assertStringContainsString('second: changed (translations.en-GB.subject)', $tester->getDisplay());
    }

    public function testExportUsesConfiguredDirectoryWhenOptionIsOmitted(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Subject')]);
        $storage = new CommandStorage();
        $tester = $this->exportTester($repository, $storage, 'configured/mail-templates');

        $status = $tester->execute(['technical-name' => 'first']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([
            ['first', $this->projectRoot . '/configured/mail-templates'],
        ], $storage->writes);
    }

    public function testExportRejectsAbsoluteConfiguredDirectoryThatWouldBeAllowedAsCliOverride(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Subject')]);
        $storage = new CommandStorage();
        $absoluteDirectory = dirname($this->projectRoot) . '/configured-mail-templates';
        $tester = $this->exportTester($repository, $storage, $absoluteDirectory);

        $status = $tester->execute(['technical-name' => 'first']);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $storage->writes);
    }

    public function testExportAllowsExplicitAbsoluteCliOverride(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Subject')]);
        $storage = new CommandStorage();
        $absoluteDirectory = dirname($this->projectRoot) . '/cli-mail-templates';
        $tester = $this->exportTester($repository, $storage, dirname($this->projectRoot) . '/configured-mail-templates');

        $status = $tester->execute([
            'technical-name' => 'first',
            '--directory' => $absoluteDirectory,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([['first', $absoluteDirectory]], $storage->writes);
    }

    public function testImportDryRunDoesNotPromptOrWriteAndPrintsRedactedSummary(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Old', '<p>old secret</p>')]);
        $storage = new CommandStorage(['first' => $this->template('first', 'New', '<p>new secret</p>')], ['first']);
        $tester = $this->importTester($repository, $storage, 0);

        $status = $tester->execute([
            '--all' => true,
            '--directory' => '/portable/templates',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([['first', '/portable/templates']], $storage->reads);
        self::assertSame([], $repository->updates);
        self::assertStringContainsString('Import dry run complete: 1 processed, 1 changed.', $tester->getDisplay());
        self::assertStringContainsString('translations.en-GB.contentHtml', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay());
    }

    public function testImportPrintsChangedOrUnchangedForEveryProcessedTemplate(): void
    {
        $first = $this->template('first', 'Unchanged');
        $repository = new CommandRepository([
            'first' => $first,
            'second' => $this->template('second', 'Old subject'),
        ]);
        $storage = new CommandStorage([
            'first' => $first,
            'second' => $this->template('second', 'New subject'),
        ], ['first', 'second']);
        $tester = $this->importTester($repository, $storage, 0);

        $status = $tester->execute(['--all' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('first: unchanged', $tester->getDisplay());
        self::assertStringContainsString('second: changed (translations.en-GB.subject)', $tester->getDisplay());
    }

    public function testBulkImportRequiresConfirmationBeforeWriting(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Old')]);
        $storage = new CommandStorage(['first' => $this->template('first', 'New')], ['first']);
        $command = $this->importCommand($repository, $storage, 0);
        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        $status = $tester->execute(['--all' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $storage->reads);
        self::assertSame([], $repository->updates);
        self::assertStringContainsString('Import cancelled.', $tester->getDisplay());
    }

    public function testInteractiveForceStillRequiresBulkConfirmation(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Old')]);
        $storage = new CommandStorage(['first' => $this->template('first', 'New')], ['first']);
        $command = $this->importCommand($repository, $storage, 0);
        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        $status = $tester->execute(['--all' => true, '--force' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $storage->reads);
        self::assertSame([], $repository->updates);
        self::assertStringContainsString('Import cancelled.', $tester->getDisplay());
        self::assertStringContainsString(
            '--no-interaction',
            $command->getDefinition()->getOption('force')->getDescription(),
        );
    }

    public function testForcedNonInteractiveBulkImportWritesWithoutPrompt(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Old')]);
        $storage = new CommandStorage(['first' => $this->template('first', 'New')], ['first']);
        $tester = $this->importTester($repository, $storage, 1);

        $status = $tester->execute(
            ['--all' => true, '--force' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['first'], $repository->updates);
        self::assertStringContainsString('Import complete: 1 processed, 1 changed.', $tester->getDisplay());
    }

    public function testNonInteractiveBulkImportWithoutForceFailsSafely(): void
    {
        $repository = new CommandRepository(['first' => $this->template('first', 'Old')]);
        $storage = new CommandStorage(['first' => $this->template('first', 'New')], ['first']);
        $tester = $this->importTester($repository, $storage, 0);

        $status = $tester->execute(['--all' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $storage->reads);
        self::assertSame([], $repository->updates);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertNotNull($display);
        self::assertStringContainsString(
            'Bulk import requires interactive confirmation or --no-interaction together with --force.',
            $display,
        );
    }

    public function testImportAllRejectsEmptyDiscoveryResult(): void
    {
        $tester = $this->importTester(new CommandRepository(), new CommandStorage(), 0);

        $status = $tester->execute(['--all' => true, '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No mail template bundles were found', $tester->getDisplay());
    }

    public function testFailuresReturnNonZeroWithoutRevealingExceptionDetails(): void
    {
        $repository = new CommandRepository();
        $repository->failure = new SyncException(
            'The requested mail template is unavailable.',
            0,
            new RuntimeException('Invalid body: top secret template content'),
        );
        $tester = $this->exportTester($repository, new CommandStorage());

        $status = $tester->execute(['technical-name' => 'missing']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Export failed: The requested mail template is unavailable.', $tester->getDisplay());
        self::assertStringNotContainsString('top secret', $tester->getDisplay());
        self::assertStringNotContainsString('Invalid body', $tester->getDisplay());
    }

    public function testUnexpectedErrorsSurfaceFromCommands(): void
    {
        $repository = new CommandRepository();
        $repository->failure = new Error('Programming failure');
        $tester = $this->exportTester($repository, new CommandStorage());

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Programming failure');

        $tester->execute(['technical-name' => 'missing']);
    }

    private function exportTester(
        CommandRepository $repository,
        CommandStorage $storage,
        string $configuredDirectory = 'custom/mail-templates',
    ): CommandTester {
        return new CommandTester(new ExportMailTemplatesCommand(
            $this->service($repository, $storage, $this->connection(0)),
            new StorageDirectoryResolver($this->projectRoot),
            $this->systemConfig($configuredDirectory),
        ));
    }

    private function importTester(
        CommandRepository $repository,
        CommandStorage $storage,
        int $transactionCount,
    ): CommandTester {
        return new CommandTester($this->importCommand($repository, $storage, $transactionCount));
    }

    private function importCommand(
        CommandRepository $repository,
        CommandStorage $storage,
        int $transactionCount,
    ): ImportMailTemplatesCommand {
        return new ImportMailTemplatesCommand(
            $this->service($repository, $storage, $this->connection($transactionCount)),
            new StorageDirectoryResolver($this->projectRoot),
            $this->systemConfig('custom/mail-templates'),
        );
    }

    private function systemConfig(string $directory): SystemConfigService
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getString')
            ->with('SkyyMailTemplateSync.config.storageDirectory')
            ->willReturn($directory);
        $systemConfig->method('getInt')->willReturn(365);

        return $systemConfig;
    }

    private function service(
        CommandRepository $repository,
        CommandStorage $storage,
        Connection $connection,
    ): MailTemplateSyncService {
        return new MailTemplateSyncService(
            $repository,
            $storage,
            new MailTemplateDiffer(),
            new BackupManager(
                $storage,
                $this->projectRoot,
                $this->systemConfig('custom/mail-templates'),
                new MockClock('2026-07-11 12:00:00 UTC'),
            ),
            $connection,
        );
    }

    private function connection(int $transactionCount): Connection
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
            new MailTemplateTranslation('en-GB', 'Skyy Shop', $subject, 'Description', $html, 'Plain body'),
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertNotFalse($entries);

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}

final class CommandRepository implements MailTemplateRepositoryInterface
{
    /** @var list<string> */
    public array $updates = [];

    public ?Throwable $failure = null;

    /** @param array<string, MailTemplate> $templates */
    public function __construct(private readonly array $templates = []) {}

    public function listTechnicalNames(Context $context): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return array_keys($this->templates);
    }

    public function lockSystemDefault(string $technicalName): void {}

    public function fetch(string $technicalName, Context $context): MailTemplate
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->templates[$technicalName] ?? throw new RuntimeException('Template not found.');
    }

    public function validate(MailTemplate $template, Context $context): void {}

    public function update(MailTemplate $template, Context $context): void
    {
        $this->updates[] = $template->technicalName;
    }
}

final class CommandStorage implements TemplateStorageInterface
{
    /** @var list<array{string, string}> */
    public array $reads = [];

    /** @var list<array{string, string}> */
    public array $writes = [];

    /**
     * @param array<string, MailTemplate> $templates
     * @param list<string>                 $discoveredNames
     */
    public function __construct(
        private readonly array $templates = [],
        private readonly array $discoveredNames = [],
    ) {}

    public function read(string $technicalName, string $root): MailTemplate
    {
        $this->reads[] = [$technicalName, $root];

        return $this->templates[$technicalName] ?? throw new RuntimeException('Stored template not found.');
    }

    public function exists(string $technicalName, string $root): bool
    {
        return isset($this->templates[$technicalName]);
    }

    public function write(MailTemplate $template, string $root): void
    {
        $this->writes[] = [$template->technicalName, $root];
    }

    public function discover(string $root): array
    {
        return $this->discoveredNames;
    }
}
