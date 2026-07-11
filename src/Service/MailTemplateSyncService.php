<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Service;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Skyyware\SkyyMailTemplateSync\Repository\MailTemplateRepositoryInterface;
use Skyyware\SkyyMailTemplateSync\Storage\TemplateStorageInterface;

final readonly class MailTemplateSyncService
{
    public function __construct(
        private MailTemplateRepositoryInterface $repository,
        private TemplateStorageInterface $storage,
        private MailTemplateDiffer $differ,
        private BackupManager $backupManager,
        private Connection $connection,
    ) {}

    public function export(?string $technicalName, bool $all, string $root, Context $context): SyncResult
    {
        $technicalNames = $this->selectedNames(
            $technicalName,
            $all,
            fn(): array => $this->repository->listTechnicalNames($context),
            'No system-default mail templates were found.',
        );
        $changedFields = [];

        foreach ($technicalNames as $name) {
            $template = $this->repository->fetch($name, $context);
            $current = $this->storage->exists($name, $root) ? $this->storage->read($name, $root) : null;
            $changes = $this->differ->diffForExport($current, $template);
            $this->storage->write($template, $root);

            if ($changes !== []) {
                $changedFields[$name] = $changes;
            }
        }

        return new SyncResult($technicalNames, $changedFields);
    }

    public function import(
        ?string $technicalName,
        bool $all,
        string $root,
        bool $dryRun,
        Context $context,
    ): SyncResult {
        $technicalNames = $this->selectedNames(
            $technicalName,
            $all,
            fn(): array => $this->storage->discover($root),
            'No mail template bundles were found in the storage directory.',
        );

        $incomingTemplates = [];
        foreach ($technicalNames as $name) {
            $incomingTemplates[$name] = $this->storage->read($name, $root);
        }

        $changedFields = [];
        foreach ($incomingTemplates as $name => $incoming) {
            $current = $this->repository->fetch($name, $context);
            $changes = $this->differ->diffForImport($current, $incoming);
            if ($changes !== []) {
                $changedFields[$name] = $changes;
            }
        }

        foreach ($incomingTemplates as $incoming) {
            $this->repository->validate($incoming, $context);
        }

        if ($dryRun) {
            return new SyncResult($technicalNames, $changedFields);
        }

        $this->backupManager->validateConfiguration();
        $transactionChangedFields = [];
        foreach (array_keys($changedFields) as $name) {
            $incoming = $incomingTemplates[$name];
            $this->connection->transactional(function () use ($name, $incoming, $context, &$transactionChangedFields): void {
                $this->repository->lockSystemDefault($name);
                $current = $this->repository->fetch($name, $context);
                $changes = $this->differ->diffForImport($current, $incoming);
                if ($changes === []) {
                    return;
                }

                $this->backupManager->backup($current);
                $this->repository->update($incoming, $context);
                $transactionChangedFields[$name] = $changes;
            });
        }

        return new SyncResult($technicalNames, $transactionChangedFields);
    }

    /**
     * @param callable(): list<string> $allNames
     *
     * @return list<string>
     */
    private function selectedNames(
        ?string $technicalName,
        bool $all,
        callable $allNames,
        string $emptySelectionMessage,
    ): array {
        if ($all === ($technicalName !== null)) {
            throw new InvalidArgumentException('Select exactly one technical name or use all templates.');
        }

        if ($technicalName !== null) {
            if (!MailTemplate::isValidTechnicalName($technicalName)) {
                throw new InvalidArgumentException('The technical name contains unsupported characters.');
            }

            return [$technicalName];
        }

        $names = $allNames();
        if ($names === []) {
            throw new SyncException($emptySelectionMessage);
        }

        return $names;
    }
}
