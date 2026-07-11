<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Repository;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateTranslation\MailTemplateTranslationEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Language\LanguageCollection;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;

final readonly class ShopwareMailTemplateRepository implements MailTemplateRepositoryInterface
{
    /**
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     * @param EntityRepository<LanguageCollection>     $languageRepository
     */
    public function __construct(
        private EntityRepository $mailTemplateRepository,
        private EntityRepository $languageRepository,
        private Connection $connection,
    ) {}

    public function listTechnicalNames(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('systemDefault', true))
            ->addAssociation('mailTemplateType');

        $technicalNames = [];
        foreach ($this->mailTemplateRepository->search($criteria, $context) as $entity) {
            if ($entity->getMailTemplateType() === null) {
                throw new SyncException('A system-default mail template has no loaded mail template type.');
            }

            $technicalNames[] = $entity->getMailTemplateType()->getTechnicalName();
        }

        $technicalNames = array_values(array_unique($technicalNames));
        sort($technicalNames);

        return $technicalNames;
    }

    public function lockSystemDefault(string $technicalName): void
    {
        // This is the sole direct table access: it coordinates concurrent DAL writes only.
        $lockedId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT `mail_template`.`id`
                FROM `mail_template`
                WHERE `mail_template`.`system_default` = 1
                  AND EXISTS (
                      SELECT 1
                      FROM `mail_template_type`
                      WHERE `mail_template_type`.`id` = `mail_template`.`mail_template_type_id`
                        AND `mail_template_type`.`technical_name` = :technicalName
                  )
                ORDER BY `mail_template`.`id`
                LIMIT 1
                FOR UPDATE
                SQL,
            ['technicalName' => $technicalName],
        );

        if ($lockedId === false) {
            throw new SyncException(sprintf('No system-default mail template exists for "%s".', $technicalName));
        }
    }

    public function fetch(string $technicalName, Context $context): MailTemplate
    {
        $entity = $this->findSystemDefault($technicalName, $context, true);
        $translations = $entity->getTranslations();
        if ($translations === null) {
            throw new SyncException(sprintf('Translations were not loaded for mail template "%s".', $technicalName));
        }

        $mappedTranslations = [];
        foreach ($translations as $translation) {
            $mapped = $this->mapTranslation($translation, $technicalName);
            $this->resolveLanguageId($mapped->locale, $context);
            $mappedTranslations[] = $mapped;
        }

        usort(
            $mappedTranslations,
            static fn(MailTemplateTranslation $left, MailTemplateTranslation $right): int => $left->locale <=> $right->locale,
        );

        return new MailTemplate($technicalName, $mappedTranslations);
    }

    public function update(MailTemplate $template, Context $context): void
    {
        $entity = $this->findSystemDefault($template->technicalName, $context);
        $translations = [];

        foreach ($template->translations as $translation) {
            $languageId = $this->resolveLanguageId($translation->locale, $context);
            $translations[$languageId] = [
                'senderName' => $translation->senderName,
                'subject' => $translation->subject,
                'description' => $translation->description,
                'contentHtml' => $translation->contentHtml,
                'contentPlain' => $translation->contentPlain,
            ];
        }

        $this->mailTemplateRepository->update([[
            'id' => $entity->getId(),
            'translations' => $translations,
        ]], $context);
    }

    public function validate(MailTemplate $template, Context $context): void
    {
        foreach ($template->translations as $translation) {
            $this->resolveLanguageId($translation->locale, $context);
        }
    }

    private function findSystemDefault(string $technicalName, Context $context, bool $withTranslations = false): MailTemplateEntity
    {
        $criteria = (new Criteria())
            ->addFilter(
                new EqualsFilter('systemDefault', true),
                new EqualsFilter('mailTemplateType.technicalName', $technicalName),
            )
            ->addAssociation('mailTemplateType')
            ->addSorting(new FieldSorting('id'))
            ->setLimit(1);

        if ($withTranslations) {
            $criteria->addAssociation('translations.language.locale');
        }

        $entity = $this->mailTemplateRepository->search($criteria, $context)->first();
        if (!$entity instanceof MailTemplateEntity) {
            throw new SyncException(sprintf('No system-default mail template exists for "%s".', $technicalName));
        }

        return $entity;
    }

    private function mapTranslation(MailTemplateTranslationEntity $translation, string $technicalName): MailTemplateTranslation
    {
        $locale = $translation->getLanguage()?->getLocale();
        if ($locale === null) {
            throw new SyncException(sprintf('A translation locale was not loaded for mail template "%s".', $technicalName));
        }

        return new MailTemplateTranslation(
            $locale->getCode(),
            $translation->getSenderName(),
            $translation->getSubject(),
            $translation->getDescription(),
            $translation->getContentHtml(),
            $translation->getContentPlain(),
        );
    }

    private function resolveLanguageId(string $locale, Context $context): string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('locale.code', $locale))
            ->addAssociation('locale')
            ->addSorting(new FieldSorting('id'));

        $languages = $this->languageRepository->search($criteria, $context)->getEntities();
        if ($languages->count() > 1) {
            throw new SyncException(sprintf(
                'Locale "%s" matches multiple Shopware languages; assign a unique locale before exporting or importing.',
                $locale,
            ));
        }

        $language = $languages->first();
        if (!$language instanceof LanguageEntity) {
            throw new SyncException(sprintf('No Shopware language exists for locale "%s".', $locale));
        }

        return $language->getId();
    }
}
