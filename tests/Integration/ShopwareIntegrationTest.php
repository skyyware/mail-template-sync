<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Integration;

use Closure;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Repository\MailTemplateRepositoryInterface;
use Skyyware\SkyyMailTemplateSync\Service\BackupManager;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateDiffer;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateSyncService;
use Skyyware\SkyyMailTemplateSync\Storage\FilesystemTemplateStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;

class MailTemplateDalIntegrationTest extends TestCase
{
    use KernelTestBehaviour;

    private Context $context;

    private Connection $connection;

    private string $localeCode;

    /** @var EntityRepository<MailTemplateCollection> */
    private EntityRepository $mailTemplateRepository;

    private string $projectRoot;

    private string $technicalName;

    private string $templateId;

    /** @var EntityRepository<MailTemplateTypeCollection> */
    private EntityRepository $typeRepository;

    private string $typeId;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->technicalName = 'skyy_integration_' . strtolower(Uuid::randomHex());
        $this->typeId = Uuid::randomHex();
        $this->templateId = Uuid::randomHex();
        $this->projectRoot = sys_get_temp_dir() . '/skyy-mail-template-integration-' . Uuid::randomHex();
        mkdir($this->projectRoot);

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        /** @var EntityRepository<MailTemplateTypeCollection> $typeRepository */
        $typeRepository = self::getContainer()->get('mail_template_type.repository');
        $this->typeRepository = $typeRepository;

        /** @var EntityRepository<MailTemplateCollection> $mailTemplateRepository */
        $mailTemplateRepository = self::getContainer()->get('mail_template.repository');
        $this->mailTemplateRepository = $mailTemplateRepository;

        /** @var EntityRepository<LanguageCollection> $languageRepository */
        $languageRepository = self::getContainer()->get('language.repository');
        $language = $languageRepository->search(
            (new Criteria([Defaults::LANGUAGE_SYSTEM]))->addAssociation('locale'),
            $this->context,
        )->first();
        self::assertNotNull($language);
        self::assertNotNull($language->getLocale());
        $this->localeCode = $language->getLocale()->getCode();

        $this->typeRepository->create([[
            'id' => $this->typeId,
            'technicalName' => $this->technicalName,
            'availableEntities' => [],
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => ['name' => 'Skyy integration test'],
            ],
        ]], $this->context);
        $this->mailTemplateRepository->create([[
            'id' => $this->templateId,
            'mailTemplateTypeId' => $this->typeId,
            'systemDefault' => true,
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'senderName' => null,
                    'subject' => 'Before integration update',
                    'description' => null,
                    'contentHtml' => '<p>Before integration update</p>',
                    'contentPlain' => 'Before integration update',
                ],
            ],
        ]], $this->context);
    }

    protected function tearDown(): void
    {
        if (isset($this->mailTemplateRepository, $this->templateId)) {
            $this->mailTemplateRepository->delete([['id' => $this->templateId]], $this->context);
        }
        if (isset($this->typeRepository, $this->typeId)) {
            $this->typeRepository->delete([['id' => $this->typeId]], $this->context);
        }
        if (isset($this->projectRoot)) {
            $this->removeDirectory($this->projectRoot);
        }
    }

    public function testRealDalUpdatePreservesNullableTranslationValues(): void
    {
        $repository = $this->productionRepository();
        $repository->update(new MailTemplate($this->technicalName, [
            new MailTemplateTranslation(
                $this->localeCode,
                null,
                'After integration update',
                null,
                '<p>After integration update</p>',
                'After integration update',
            ),
        ]), $this->context);

        $translation = $repository->fetch($this->technicalName, $this->context)->translations[0];
        self::assertNull($translation->senderName);
        self::assertSame('After integration update', $translation->subject);
        self::assertNull($translation->description);
        self::assertSame('<p>After integration update</p>', $translation->contentHtml);
        self::assertSame('After integration update', $translation->contentPlain);
    }

    public function testSyncTransactionRollsBackRealDalUpdateAndKeepsFreshBackup(): void
    {
        $storage = new FilesystemTemplateStorage();
        $incoming = new MailTemplate($this->technicalName, [
            new MailTemplateTranslation(
                $this->localeCode,
                'Integration sender',
                'Attempted transaction update',
                'Attempted description',
                '<p>Attempted transaction update</p>',
                'Attempted transaction update',
            ),
        ]);
        $importRoot = $this->projectRoot . '/imports';
        $storage->write($incoming, $importRoot);

        $delegate = $this->productionRepository();
        $failingRepository = new FailingAfterDalUpdateRepository($delegate);
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(365);
        $service = new MailTemplateSyncService(
            $failingRepository,
            $storage,
            new MailTemplateDiffer(),
            new BackupManager($storage, $this->projectRoot, $systemConfig),
            $this->connection,
        );

        try {
            $service->import($this->technicalName, false, $importRoot, false, $this->context);
            self::fail('The forced post-update failure was not raised.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced failure after real DAL update.', $exception->getMessage());
        }

        self::assertTrue($failingRepository->updateCompleted);
        $restored = $delegate->fetch($this->technicalName, $this->context)->translations[0];
        self::assertSame('Before integration update', $restored->subject);
        self::assertNull($restored->senderName);
        self::assertNull($restored->description);

        $backups = glob($this->projectRoot . '/var/skyy-mail-template-sync/backups/*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $backups);
        $backup = $storage->read($this->technicalName, $backups[0])->translations[0];
        self::assertSame('Before integration update', $backup->subject);
        self::assertNull($backup->senderName);
        self::assertNull($backup->description);
    }

    public function testConcurrentDalUpdateCannotSlipBetweenFreshSnapshotAndBackup(): void
    {
        $storage = new FilesystemTemplateStorage();
        $incoming = new MailTemplate($this->technicalName, [
            new MailTemplateTranslation(
                $this->localeCode,
                'Import sender',
                'Imported subject',
                'Imported description',
                '<p>Imported body</p>',
                'Imported body',
            ),
        ]);
        $importRoot = $this->projectRoot . '/imports';
        $storage->write($incoming, $importRoot);

        $delegate = $this->productionRepository();
        $this->prepareConcurrentAdminUpdate();
        $repository = new AfterFreshFetchRepository(
            $delegate,
            fn(): bool => $this->signalConcurrentAdminUpdateAndObserveLockRejection(),
        );
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('getInt')->willReturn(365);
        $service = new MailTemplateSyncService(
            $repository,
            $storage,
            new MailTemplateDiffer(),
            new BackupManager($storage, $this->projectRoot, $systemConfig),
            $this->connection,
        );

        $childMessages = [];
        try {
            $service->import($this->technicalName, false, $importRoot, false, $this->context);
        } finally {
            $childMessages = $this->finishConcurrentAdminUpdate();
        }

        self::assertTrue($repository->observedLockRejection);
        self::assertContains('locked', $childMessages);
        self::assertSame(
            'Imported subject',
            $delegate->fetch($this->technicalName, $this->context)->translations[0]->subject,
        );

        $backups = glob($this->projectRoot . '/var/skyy-mail-template-sync/backups/*', GLOB_ONLYDIR) ?: [];
        self::assertCount(1, $backups);
        self::assertSame(
            'Before integration update',
            $storage->read($this->technicalName, $backups[0])->translations[0]->subject,
        );
    }

    /** @var null|resource */
    private $concurrentProcess = null;

    /** @var array<int, resource> */
    private array $concurrentPipes = [];

    private int $concurrentChildConnectionId = 0;

    private int $concurrentParentConnectionId = 0;

    private bool $concurrentUpdateSignalled = false;

    /** @var list<string> */
    private array $concurrentMessages = [];

    private function prepareConcurrentAdminUpdate(): void
    {
        $shopwareProjectRoot = getenv('SHOPWARE_PROJECT_ROOT');
        self::assertIsString($shopwareProjectRoot);
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);
        $environment = getenv();
        $environment['APP_DEBUG'] = '0';
        $environment['APP_ENV'] = 'test';
        $environment['DATABASE_URL'] = $databaseUrl;
        $environment['PROJECT_ROOT'] = $shopwareProjectRoot;

        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/concurrent-update.php', $shopwareProjectRoot, $this->templateId],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $shopwareProjectRoot,
            $environment,
        );
        self::assertIsResource($process);
        $this->concurrentProcess = $process;
        $this->concurrentPipes = $pipes;

        $ready = fgets($pipes[1]);
        if (!is_string($ready)) {
            stream_set_blocking($pipes[2], false);
            self::fail('Concurrent update helper did not become ready: ' . stream_get_contents($pipes[2]));
        }
        self::assertStringStartsWith('ready:', $ready);
        $this->concurrentChildConnectionId = (int) trim(substr($ready, strlen('ready:')));
        $this->concurrentParentConnectionId = (int) $this->connection->fetchOne('SELECT CONNECTION_ID()');
        self::assertNotSame(
            $this->concurrentParentConnectionId,
            $this->concurrentChildConnectionId,
            'The regression requires two real database connections.',
        );
    }

    private function signalConcurrentAdminUpdateAndObserveLockRejection(): bool
    {
        self::assertArrayHasKey(0, $this->concurrentPipes);
        fwrite($this->concurrentPipes[0], "update\n");
        fflush($this->concurrentPipes[0]);
        $this->concurrentUpdateSignalled = true;

        stream_set_blocking($this->concurrentPipes[1], true);
        stream_set_timeout($this->concurrentPipes[1], 10);
        $message = fgets($this->concurrentPipes[1]);
        if (!is_string($message)) {
            stream_set_blocking($this->concurrentPipes[2], false);
            self::fail('Concurrent DAL update returned no result: ' . stream_get_contents($this->concurrentPipes[2]));
        }

        $message = trim($message);
        $this->concurrentMessages[] = $message;

        return $message === 'locked';
    }

    /** @return list<string> */
    private function finishConcurrentAdminUpdate(): array
    {
        if (!is_resource($this->concurrentProcess) || $this->concurrentPipes === []) {
            return [];
        }

        if (!$this->concurrentUpdateSignalled) {
            fwrite($this->concurrentPipes[0], "cancel\n");
            fflush($this->concurrentPipes[0]);
        }
        fclose($this->concurrentPipes[0]);

        stream_set_blocking($this->concurrentPipes[1], true);
        stream_set_timeout($this->concurrentPipes[1], 10);
        $stdout = stream_get_contents($this->concurrentPipes[1]);
        $stderr = stream_get_contents($this->concurrentPipes[2]);
        fclose($this->concurrentPipes[1]);
        fclose($this->concurrentPipes[2]);
        $this->concurrentPipes = [];

        $exitCode = proc_close($this->concurrentProcess);
        $this->concurrentProcess = null;
        self::assertSame(0, $exitCode, $stderr);

        $messages = [
            ...$this->concurrentMessages,
            ...array_values(array_filter(array_map('trim', explode("\n", $stdout)))),
        ];
        $this->concurrentChildConnectionId = 0;
        $this->concurrentParentConnectionId = 0;
        $this->concurrentUpdateSignalled = false;
        $this->concurrentMessages = [];

        return $messages;
    }

    private function productionRepository(): MailTemplateRepositoryInterface
    {
        $repository = self::getContainer()->get(MailTemplateRepositoryInterface::class);
        self::assertInstanceOf(MailTemplateRepositoryInterface::class, $repository);

        return $repository;
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

final class ShopwareIntegrationTest extends MailTemplateDalIntegrationTest
{
    public function testInstalledShopwareCoreMatchesConfiguredExpectation(): void
    {
        $installedVersion = InstalledVersions::getPrettyVersion('shopware/core');
        self::assertNotNull($installedVersion);

        $expectedVersion = getenv('EXPECTED_SHOPWARE_CORE_VERSION');
        if (is_string($expectedVersion) && $expectedVersion !== '') {
            self::assertSame(ltrim($expectedVersion, 'v'), ltrim($installedVersion, 'v'));
        }
    }

    public function testContainerCompilesAndRegistersBothCommands(): void
    {
        self::assertInstanceOf(
            MailTemplateSyncService::class,
            self::getContainer()->get(MailTemplateSyncService::class),
        );

        $application = new Application(self::getKernel());
        self::assertTrue($application->has('skyy:mail-template:export'));
        self::assertTrue($application->has('skyy:mail-template:import'));
    }
}

final class FailingAfterDalUpdateRepository implements MailTemplateRepositoryInterface
{
    public bool $updateCompleted = false;

    public function __construct(private readonly MailTemplateRepositoryInterface $delegate) {}

    public function listTechnicalNames(Context $context): array
    {
        return $this->delegate->listTechnicalNames($context);
    }

    public function lockSystemDefault(string $technicalName): void
    {
        $this->delegate->lockSystemDefault($technicalName);
    }

    public function fetch(string $technicalName, Context $context): MailTemplate
    {
        return $this->delegate->fetch($technicalName, $context);
    }

    public function validate(MailTemplate $template, Context $context): void
    {
        $this->delegate->validate($template, $context);
    }

    public function update(MailTemplate $template, Context $context): void
    {
        $this->delegate->update($template, $context);
        $this->updateCompleted = true;

        throw new RuntimeException('Forced failure after real DAL update.');
    }
}

final class AfterFreshFetchRepository implements MailTemplateRepositoryInterface
{
    public bool $observedLockRejection = false;

    private int $fetchCount = 0;

    /** @param Closure(): bool $afterFreshFetch */
    public function __construct(
        private readonly MailTemplateRepositoryInterface $delegate,
        private readonly Closure $afterFreshFetch,
    ) {}

    public function listTechnicalNames(Context $context): array
    {
        return $this->delegate->listTechnicalNames($context);
    }

    public function lockSystemDefault(string $technicalName): void
    {
        $this->delegate->lockSystemDefault($technicalName);
    }

    public function fetch(string $technicalName, Context $context): MailTemplate
    {
        $template = $this->delegate->fetch($technicalName, $context);
        ++$this->fetchCount;

        if ($this->fetchCount === 2) {
            $this->observedLockRejection = ($this->afterFreshFetch)();
        }

        return $template;
    }

    public function validate(MailTemplate $template, Context $context): void
    {
        $this->delegate->validate($template, $context);
    }

    public function update(MailTemplate $template, Context $context): void
    {
        $this->delegate->update($template, $context);
    }
}
