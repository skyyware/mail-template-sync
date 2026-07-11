<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Service;

use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;

final class MailTemplateDiffer
{
    private const TRANSLATION_FIELDS = [
        'senderName',
        'subject',
        'description',
        'contentHtml',
        'contentPlain',
    ];

    /**
     * @return list<string>
     */
    public function diffForImport(MailTemplate $current, MailTemplate $incoming): array
    {
        return $this->diff($current, $incoming, false);
    }

    /**
     * @return list<string>
     */
    public function diffForExport(?MailTemplate $current, MailTemplate $source): array
    {
        return $this->diff($current, $source, true);
    }

    /**
     * @return list<string>
     */
    private function diff(?MailTemplate $current, MailTemplate $incoming, bool $authoritative): array
    {
        $currentTranslations = $current === null ? [] : $this->translationsByLocale($current);
        $incomingTranslations = $this->translationsByLocale($incoming);
        $locales = array_keys($incomingTranslations);

        if ($authoritative) {
            $locales = array_unique([...$locales, ...array_keys($currentTranslations)]);
        }

        sort($locales, SORT_STRING);

        $changedFields = [];
        foreach ($locales as $locale) {
            $currentTranslation = $currentTranslations[$locale] ?? null;
            $incomingTranslation = $incomingTranslations[$locale] ?? null;

            if ($incomingTranslation === null) {
                $changedFields[] = sprintf('translations.%s', $locale);

                continue;
            }

            foreach (self::TRANSLATION_FIELDS as $field) {
                if ($currentTranslation === null || $currentTranslation->{$field} !== $incomingTranslation->{$field}) {
                    $changedFields[] = sprintf('translations.%s.%s', $locale, $field);
                }
            }
        }

        sort($changedFields, SORT_STRING);

        return $changedFields;
    }

    /**
     * @return array<string, MailTemplateTranslation>
     */
    private function translationsByLocale(MailTemplate $template): array
    {
        $translations = [];
        foreach ($template->translations as $translation) {
            $translations[$translation->locale] = $translation;
        }

        return $translations;
    }
}
