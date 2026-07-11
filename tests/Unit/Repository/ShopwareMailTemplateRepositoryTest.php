<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateTranslation\MailTemplateTranslationCollection;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateTranslation\MailTemplateTranslationEntity;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Repository\ShopwareMailTemplateRepository;

final class ShopwareMailTemplateRepositoryTest extends TestCase
{
    private Context $context;

    /** @var EntityRepository<MailTemplateCollection>&MockObject */
    private EntityRepository $mailTemplateRepository;

    /** @var EntityRepository<LanguageCollection>&MockObject */
    private EntityRepository $languageRepository;

    /** @var Connection&MockObject */
    private Connection $connection;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->mailTemplateRepository = $this->createMock(EntityRepository::class);
        $this->languageRepository = $this->createMock(EntityRepository::class);
        $this->connection = $this->createMock(Connection::class);
    }

    public function testListsTechnicalNamesOfSystemDefaultTemplates(): void
    {
        $first = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $second = $this->shopwareTemplate('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'customer_register');

        $this->mailTemplateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria): bool {
                    self::assertEquals([new EqualsFilter('systemDefault', true)], $criteria->getFilters());
                    self::assertTrue($criteria->hasAssociation('mailTemplateType'));

                    return true;
                }),
                $this->context,
            )
            ->willReturn($this->searchResult(new MailTemplateCollection([$first, $second])));

        $repository = $this->repository();

        self::assertSame(['customer_register', 'order_confirmation_mail'], $repository->listTechnicalNames($this->context));
    }

    public function testFetchesSystemDefaultTemplateWithEveryTranslationAndLocale(): void
    {
        $template = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $template->setTranslations(new MailTemplateTranslationCollection([
            $this->shopwareTranslation('language-en', 'en-GB', 'Order confirmation', 'Order received'),
            $this->shopwareTranslation('language-de', 'de-DE', 'Bestellbestatigung', 'Bestellung erhalten'),
        ]));

        $this->mailTemplateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria): bool {
                    self::assertEquals([
                        new EqualsFilter('systemDefault', true),
                        new EqualsFilter('mailTemplateType.technicalName', 'order_confirmation_mail'),
                    ], $criteria->getFilters());
                    self::assertTrue($criteria->hasAssociation('mailTemplateType'));
                    self::assertTrue($criteria->hasAssociation('translations'));
                    self::assertTrue($criteria->getAssociation('translations')->hasAssociation('language'));
                    self::assertTrue($criteria->getAssociation('translations.language')->hasAssociation('locale'));

                    return true;
                }),
                $this->context,
            )
            ->willReturn($this->searchResult(new MailTemplateCollection([$template])));
        $this->expectUniqueLanguageLookups([
            'de-DE' => $this->language('language-de', 'de-DE'),
            'en-GB' => $this->language('language-en', 'en-GB'),
        ]);

        $result = $this->repository()->fetch('order_confirmation_mail', $this->context);

        self::assertSame('order_confirmation_mail', $result->technicalName);
        self::assertSame(['de-DE', 'en-GB'], array_column($result->translations, 'locale'));
        self::assertSame('Bestellbestatigung', $result->translations[0]->subject);
        self::assertSame('Order received', $result->translations[1]->description);
    }

    public function testFetchPreservesNullableTranslationFields(): void
    {
        $template = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $template->setTranslations(new MailTemplateTranslationCollection([
            $this->shopwareTranslation('language-en', 'en-GB', null, null, null, null, null),
        ]));
        $this->mailTemplateRepository->method('search')
            ->willReturn($this->searchResult(new MailTemplateCollection([$template])));
        $this->expectUniqueLanguageLookups([
            'en-GB' => $this->language('language-en', 'en-GB'),
        ]);

        $translation = $this->repository()->fetch('order_confirmation_mail', $this->context)->translations[0];

        self::assertNull($translation->senderName);
        self::assertNull($translation->subject);
        self::assertNull($translation->description);
        self::assertNull($translation->contentHtml);
        self::assertNull($translation->contentPlain);
    }

    public function testFetchRejectsLocaleThatMapsToMultipleLanguages(): void
    {
        $template = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $template->setTranslations(new MailTemplateTranslationCollection([
            $this->shopwareTranslation('language-en-a', 'en-GB', 'Subject', 'Description'),
        ]));
        $this->mailTemplateRepository->method('search')
            ->willReturn($this->searchResult(new MailTemplateCollection([$template])));
        $this->languageRepository->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Criteria $criteria): bool {
                $this->assertDeterministicLocaleCriteria($criteria, 'en-GB');

                return true;
            }), $this->context)
            ->willReturn($this->searchResult(new LanguageCollection([
                $this->language('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'en-GB'),
                $this->language('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'en-GB'),
            ])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Locale "en-GB" matches multiple Shopware languages; assign a unique locale before exporting or importing.',
        );

        $this->repository()->fetch('order_confirmation_mail', $this->context);
    }

    public function testFetchRejectsMissingSystemDefaultTemplate(): void
    {
        $this->mailTemplateRepository->method('search')
            ->willReturn($this->searchResult(new MailTemplateCollection()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No system-default mail template exists for "missing_template".');

        $this->repository()->fetch('missing_template', $this->context);
    }

    public function testLocksSystemDefaultRowWithOneParameterizedCoordinationQuery(): void
    {
        $this->mailTemplateRepository->expects(self::never())->method('search');
        $this->languageRepository->expects(self::never())->method('search');
        $this->connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::callback(function (string $sql): bool {
                    self::assertStringContainsString('FROM `mail_template`', $sql);
                    self::assertStringContainsString('FROM `mail_template_type`', $sql);
                    self::assertStringContainsString(':technicalName', $sql);
                    self::assertStringContainsString('FOR UPDATE', $sql);
                    self::assertStringNotContainsString('order_confirmation_mail', $sql);
                    self::assertStringNotContainsString('mail_template_translation', $sql);

                    return true;
                }),
                ['technicalName' => 'order_confirmation_mail'],
            )
            ->willReturn('locked-row');

        $this->repository()->lockSystemDefault('order_confirmation_mail');
    }

    public function testValidatesEveryIncomingLocaleAgainstInstalledLanguages(): void
    {
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Order', 'Description', '<p>Body</p>', 'Body'),
            new MailTemplateTranslation('de-DE', 'Skyy Shop', 'Bestellung', 'Beschreibung', '<p>Inhalt</p>', 'Inhalt'),
        ]);
        $languageResults = [
            'en-GB' => $this->language('11111111111111111111111111111111', 'en-GB'),
            'de-DE' => $this->language('22222222222222222222222222222222', 'de-DE'),
        ];
        $this->mailTemplateRepository->expects(self::never())->method('search');
        $this->mailTemplateRepository->expects(self::never())->method('update');
        $this->languageRepository->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($languageResults): EntitySearchResult {
                self::assertSame($this->context, $context);
                $filter = $criteria->getFilters()[0] ?? null;
                self::assertInstanceOf(EqualsFilter::class, $filter);
                self::assertSame('locale.code', $filter->getField());
                $locale = $filter->getValue();
                self::assertIsString($locale);
                $this->assertDeterministicLocaleCriteria($criteria, $locale);

                return $this->searchResult(new LanguageCollection([$languageResults[$locale]]));
            });

        $this->repository()->validate($template, $this->context);
    }

    public function testValidationRejectsLocaleThatMapsToMultipleLanguages(): void
    {
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Order', 'Description', '<p>Body</p>', 'Body'),
        ]);
        $this->mailTemplateRepository->expects(self::never())->method('search');
        $this->mailTemplateRepository->expects(self::never())->method('update');
        $this->languageRepository->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Criteria $criteria): bool {
                $this->assertDeterministicLocaleCriteria($criteria, 'en-GB');

                return true;
            }), $this->context)
            ->willReturn($this->searchResult(new LanguageCollection([
                $this->language('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'en-GB'),
                $this->language('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'en-GB'),
            ])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Locale "en-GB" matches multiple Shopware languages; assign a unique locale before exporting or importing.',
        );

        $this->repository()->validate($template, $this->context);
    }

    public function testValidationRejectsIncomingLocaleWithoutInstalledLanguage(): void
    {
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('fr-FR', 'Skyy Shop', 'Confirmation', 'Description', '<p>Merci</p>', 'Merci'),
        ]);
        $this->mailTemplateRepository->expects(self::never())->method('search');
        $this->mailTemplateRepository->expects(self::never())->method('update');
        $this->languageRepository->method('search')
            ->willReturn($this->searchResult(new LanguageCollection()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Shopware language exists for locale "fr-FR".');

        $this->repository()->validate($template, $this->context);
    }

    public function testUpdatesExistingSystemDefaultTemplateWithResolvedLanguageIdsAndNoSourceUuid(): void
    {
        $target = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Order confirmation', 'Order received', '<p>Thank you</p>', 'Thank you'),
            new MailTemplateTranslation('de-DE', 'Skyy Shop', 'Bestellbestatigung', 'Bestellung erhalten', '<p>Danke</p>', 'Danke'),
        ]);

        $this->mailTemplateRepository->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Criteria $criteria): bool {
                self::assertEquals([
                    new EqualsFilter('systemDefault', true),
                    new EqualsFilter('mailTemplateType.technicalName', 'order_confirmation_mail'),
                ], $criteria->getFilters());

                return true;
            }), $this->context)
            ->willReturn($this->searchResult(new MailTemplateCollection([$target])));

        $languageResults = [
            'en-GB' => $this->language('11111111111111111111111111111111', 'en-GB'),
            'de-DE' => $this->language('22222222222222222222222222222222', 'de-DE'),
        ];
        $this->languageRepository->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($languageResults): EntitySearchResult {
                self::assertSame($this->context, $context);
                self::assertTrue($criteria->hasAssociation('locale'));
                $filter = $criteria->getFilters()[0] ?? null;
                self::assertInstanceOf(EqualsFilter::class, $filter);
                self::assertSame('locale.code', $filter->getField());
                $locale = $filter->getValue();
                self::assertIsString($locale);
                $this->assertDeterministicLocaleCriteria($criteria, $locale);

                return $this->searchResult(new LanguageCollection([$languageResults[$locale]]));
            });

        $this->mailTemplateRepository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $payload): bool {
                self::assertSame([[
                    'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'translations' => [
                        '11111111111111111111111111111111' => [
                            'senderName' => 'Skyy Shop',
                            'subject' => 'Order confirmation',
                            'description' => 'Order received',
                            'contentHtml' => '<p>Thank you</p>',
                            'contentPlain' => 'Thank you',
                        ],
                        '22222222222222222222222222222222' => [
                            'senderName' => 'Skyy Shop',
                            'subject' => 'Bestellbestatigung',
                            'description' => 'Bestellung erhalten',
                            'contentHtml' => '<p>Danke</p>',
                            'contentPlain' => 'Danke',
                        ],
                    ],
                ]], $payload);

                return true;
            }), $this->context);

        $this->repository()->update($template, $this->context);
    }

    public function testUpdatePreservesNullValuesInDalPayload(): void
    {
        $target = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', null, null, null, null, null),
        ]);
        $this->mailTemplateRepository->method('search')
            ->willReturn($this->searchResult(new MailTemplateCollection([$target])));
        $this->expectUniqueLanguageLookups([
            'en-GB' => $this->language('11111111111111111111111111111111', 'en-GB'),
        ]);
        $this->mailTemplateRepository->expects(self::once())
            ->method('update')
            ->with([[
                'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'translations' => [
                    '11111111111111111111111111111111' => [
                        'senderName' => null,
                        'subject' => null,
                        'description' => null,
                        'contentHtml' => null,
                        'contentPlain' => null,
                    ],
                ],
            ]], $this->context);

        $this->repository()->update($template, $this->context);
    }

    public function testUpdateRejectsLocaleWithoutShopwareLanguage(): void
    {
        $target = $this->shopwareTemplate('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'order_confirmation_mail');
        $this->mailTemplateRepository->method('search')
            ->willReturn($this->searchResult(new MailTemplateCollection([$target])));
        $this->languageRepository->method('search')
            ->willReturn($this->searchResult(new LanguageCollection()));
        $this->mailTemplateRepository->expects(self::never())->method('update');

        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('fr-FR', 'Skyy Shop', 'Confirmation', 'Commande recue', '<p>Merci</p>', 'Merci'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Shopware language exists for locale "fr-FR".');

        $this->repository()->update($template, $this->context);
    }

    private function repository(): ShopwareMailTemplateRepository
    {
        return new ShopwareMailTemplateRepository(
            $this->mailTemplateRepository,
            $this->languageRepository,
            $this->connection,
        );
    }

    private function shopwareTemplate(string $id, string $technicalName): MailTemplateEntity
    {
        $type = new MailTemplateTypeEntity();
        $type->setId('type-' . $id);
        $type->setTechnicalName($technicalName);

        $template = new MailTemplateEntity();
        $template->setId($id);
        $template->setSystemDefault(true);
        $template->setMailTemplateType($type);

        return $template;
    }

    private function shopwareTranslation(
        string $languageId,
        string $localeCode,
        ?string $subject,
        ?string $description,
        ?string $senderName = 'Skyy Shop',
        ?string $contentHtml = '<p>Content</p>',
        ?string $contentPlain = 'Content',
    ): MailTemplateTranslationEntity {
        $language = $this->language($languageId, $localeCode);
        $translation = new MailTemplateTranslationEntity();
        $translation->setUniqueIdentifier($languageId);
        $translation->setLanguageId($languageId);
        $translation->setLanguage($language);
        $translation->setSenderName($senderName);
        $translation->setSubject($subject);
        $translation->setDescription($description);
        $translation->setContentHtml($contentHtml);
        $translation->setContentPlain($contentPlain);

        return $translation;
    }

    private function language(string $id, string $localeCode): LanguageEntity
    {
        $locale = new LocaleEntity();
        $locale->setId('locale-' . $id);
        $locale->setCode($localeCode);

        $language = new LanguageEntity();
        $language->setId($id);
        $language->setLocaleId($locale->getId());
        $language->setLocale($locale);

        return $language;
    }

    /**
     * @param array<string, LanguageEntity> $languagesByLocale
     */
    private function expectUniqueLanguageLookups(array $languagesByLocale): void
    {
        $this->languageRepository->expects(self::exactly(count($languagesByLocale)))
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use ($languagesByLocale): EntitySearchResult {
                self::assertSame($this->context, $context);
                $filter = $criteria->getFilters()[0] ?? null;
                self::assertInstanceOf(EqualsFilter::class, $filter);
                $locale = $filter->getValue();
                self::assertIsString($locale);
                $this->assertDeterministicLocaleCriteria($criteria, $locale);

                return $this->searchResult(new LanguageCollection([$languagesByLocale[$locale]]));
            });
    }

    private function assertDeterministicLocaleCriteria(Criteria $criteria, string $locale): void
    {
        self::assertNull($criteria->getLimit());
        self::assertTrue($criteria->hasAssociation('locale'));
        self::assertEquals([new EqualsFilter('locale.code', $locale)], $criteria->getFilters());
        self::assertCount(1, $criteria->getSorting());
        self::assertInstanceOf(FieldSorting::class, $criteria->getSorting()[0]);
        self::assertSame('id', $criteria->getSorting()[0]->getField());
        self::assertSame(FieldSorting::ASCENDING, $criteria->getSorting()[0]->getDirection());
    }

    /**
     * @template TEntityCollection of EntityCollection
     *
     * @param TEntityCollection $entities
     *
     * @return EntitySearchResult<TEntityCollection>
     */
    private function searchResult(EntityCollection $entities): EntitySearchResult
    {
        return new EntitySearchResult('test', $entities->count(), $entities, null, new Criteria(), $this->context);
    }
}
