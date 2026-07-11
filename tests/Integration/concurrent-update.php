<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteException;
use Shopware\Core\Kernel;

$projectRoot = $argv[1] ?? '';
$templateId = $argv[2] ?? '';

try {
    if (!is_file($projectRoot . '/vendor/autoload.php') || $templateId === '') {
        throw new RuntimeException('Concurrent update helper received invalid arguments.');
    }

    $classLoader = require $projectRoot . '/vendor/autoload.php';
    $kernel = KernelFactory::create(
        environment: 'test',
        debug: false,
        classLoader: $classLoader,
        pluginLoader: new StaticKernelPluginLoader($classLoader, null),
    );
    if (!$kernel instanceof Kernel) {
        throw new RuntimeException('Concurrent update helper could not create the Shopware kernel.');
    }
    $kernel->boot();

    $connection = $kernel->getContainer()->get(Connection::class);
    if (!$connection instanceof Connection) {
        throw new RuntimeException('Concurrent update helper could not load the DBAL connection.');
    }
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 0');

    /** @var EntityRepository<MailTemplateCollection> $repository */
    $repository = $kernel->getContainer()->get('mail_template.repository');
    fwrite(STDOUT, sprintf("ready:%d\n", (int) $connection->fetchOne('SELECT CONNECTION_ID()')));
    fflush(STDOUT);

    if (trim((string) fgets(STDIN)) !== 'update') {
        fwrite(STDOUT, "cancelled\n");
        $kernel->shutdown();
        exit(0);
    }

    try {
        $repository->update([[
            'id' => $templateId,
            'systemDefault' => true,
            'translations' => [
                Defaults::LANGUAGE_SYSTEM => [
                    'subject' => 'Concurrent admin subject',
                ],
            ],
        ]], Context::createDefaultContext());
    } catch (LockWaitTimeoutException) {
        fwrite(STDOUT, "locked\n");
        $kernel->shutdown();
        exit(0);
    } catch (WriteException $exception) {
        foreach ($exception->getExceptions() as $innerException) {
            if (!$innerException instanceof LockWaitTimeoutException) {
                throw $exception;
            }
        }

        fwrite(STDOUT, "locked\n");
        $kernel->shutdown();
        exit(0);
    }

    fwrite(STDOUT, "updated\n");
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
