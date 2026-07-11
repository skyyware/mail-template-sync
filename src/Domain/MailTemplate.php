<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Domain;

use InvalidArgumentException;

final readonly class MailTemplate
{
    /** @var list<MailTemplateTranslation> */
    public array $translations;

    /**
     * @param array<mixed> $translations
     */
    public function __construct(
        public string $technicalName,
        array $translations,
    ) {
        if (!self::isValidTechnicalName($this->technicalName)) {
            throw new InvalidArgumentException('The technical name contains unsupported characters.');
        }

        $locales = [];
        $normalizedTranslations = [];
        foreach ($translations as $translation) {
            if (!$translation instanceof MailTemplateTranslation) {
                throw new InvalidArgumentException('Every translation must be a MailTemplateTranslation.');
            }

            if (isset($locales[$translation->locale])) {
                throw new InvalidArgumentException(sprintf('The locale "%s" is duplicated.', $translation->locale));
            }

            $locales[$translation->locale] = true;
            $normalizedTranslations[] = $translation;
        }

        $this->translations = $normalizedTranslations;
    }

    public static function isValidTechnicalName(string $technicalName): bool
    {
        return $technicalName !== '.'
            && $technicalName !== '..'
            && preg_match('/^[A-Za-z0-9_.-]+$/', $technicalName) === 1;
    }
}
