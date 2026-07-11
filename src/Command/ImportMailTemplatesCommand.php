<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Command;

use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Skyyware\SkyyMailTemplateSync\Service\MailTemplateSyncService;
use Skyyware\SkyyMailTemplateSync\Service\SyncResult;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Skyyware\SkyyMailTemplateSync\Storage\StorageDirectoryResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'skyy:mail-template:import',
    description: 'Import portable mail templates into Shopware.',
)]
final class ImportMailTemplatesCommand extends Command
{
    private const DEFAULT_DIRECTORY = 'custom/mail-templates';

    public function __construct(
        private readonly MailTemplateSyncService $syncService,
        private readonly StorageDirectoryResolver $directoryResolver,
        private readonly SystemConfigService $systemConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('technical-name', InputArgument::OPTIONAL, 'Technical name of one mail template.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Import all discovered mail templates.')
            ->addOption(
                'directory',
                null,
                InputOption::VALUE_REQUIRED,
                'Storage directory, relative to the Shopware project or absolute.',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report changes without writing.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'With --no-interaction, acknowledge and bypass bulk import confirmation.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $technicalName = $this->technicalName($input);
        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($all === ($technicalName !== null)) {
            $io->error('Select exactly one technical name or use --all.');

            return self::FAILURE;
        }

        $bypassConfirmation = !$input->isInteractive() && (bool) $input->getOption('force');
        if ($all && !$dryRun && !$bypassConfirmation) {
            if (!$input->isInteractive()) {
                $io->error('Bulk import requires interactive confirmation or --no-interaction together with --force.');

                return self::FAILURE;
            }

            if (!$io->confirm('Import all discovered mail templates?', false)) {
                $io->warning('Import cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $directory = $this->resolvedStorageDirectory($input);
            $result = $this->syncService->import(
                $technicalName,
                $all,
                $directory,
                $dryRun,
                Context::createDefaultContext(),
            );
        } catch (InvalidArgumentException | SyncException $exception) {
            $io->error('Import failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($io, $result, $dryRun);

        return self::SUCCESS;
    }

    private function technicalName(InputInterface $input): ?string
    {
        $technicalName = $input->getArgument('technical-name');

        return is_string($technicalName) && $technicalName !== '' ? $technicalName : null;
    }

    private function resolvedStorageDirectory(InputInterface $input): string
    {
        $directory = $input->getOption('directory');
        if (is_string($directory) && $directory !== '') {
            return $this->directoryResolver->resolveOverride($directory);
        }

        $configuredDirectory = $this->systemConfig->getString('SkyyMailTemplateSync.config.storageDirectory')
            ?: self::DEFAULT_DIRECTORY;

        return $this->directoryResolver->resolveConfigured($configuredDirectory);
    }

    private function renderSummary(SymfonyStyle $io, SyncResult $result, bool $dryRun): void
    {
        $operation = $dryRun ? 'Import dry run' : 'Import';
        $io->success(sprintf(
            '%s complete: %d processed, %d changed.',
            $operation,
            count($result->processedNames),
            count($result->changedFields),
        ));

        foreach ($result->processedNames as $technicalName) {
            $fields = $result->changedFields[$technicalName] ?? [];
            $io->writeln($fields === []
                ? sprintf('%s: unchanged', $technicalName)
                : sprintf('%s: changed (%s)', $technicalName, implode(', ', $fields)));
        }
    }
}
