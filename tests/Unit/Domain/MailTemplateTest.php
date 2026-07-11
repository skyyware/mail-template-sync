<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;

final class MailTemplateTest extends TestCase
{
    #[DataProvider('reservedTechnicalNames')]
    public function testRejectsReservedTechnicalNames(string $technicalName): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailTemplate($technicalName, []);
    }

    public function testTranslationPreservesNullSeparatelyFromEmptyStrings(): void
    {
        $translation = new MailTemplateTranslation(
            'en-GB',
            null,
            '',
            null,
            '<p>Body</p>',
            null,
        );

        self::assertNull($translation->senderName);
        self::assertSame('', $translation->subject);
        self::assertNull($translation->description);
        self::assertSame('<p>Body</p>', $translation->contentHtml);
        self::assertNull($translation->contentPlain);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedTechnicalNames(): iterable
    {
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
    }
}
