<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Domain;

use InvalidArgumentException;

final readonly class MailTemplateTranslation
{
    public function __construct(
        public string $locale,
        public ?string $senderName,
        public ?string $subject,
        public ?string $description,
        public ?string $contentHtml,
        public ?string $contentPlain,
    ) {
        if (!self::isValidLocale($this->locale)) {
            throw new InvalidArgumentException('The locale must be a language and region tag, such as "en-GB".');
        }
    }

    public static function isValidLocale(string $locale): bool
    {
        return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})+$/', $locale) === 1;
    }
}
