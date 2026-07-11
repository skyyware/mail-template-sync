<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Skyyware\SkyyMailTemplateSync\Storage\FilesystemTemplateStorage;

final class FilesystemTemplateStorageTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'skyy-mail-template-storage-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);

        $this->storageRoot = $directory;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageRoot);
    }

    public function testWritesFivePortableFilesPerLocaleInDeterministicOrderAndReadsThemBack(): void
    {
        $storage = new FilesystemTemplateStorage();
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', 'Order confirmation', 'Order received', '<p>Thank you</p>', 'Thank you'),
            new MailTemplateTranslation('de-DE', 'Skyy Shop', 'Bestellbestatigung', 'Bestellung erhalten', '<p>Danke</p>', 'Danke'),
        ]);

        $storage->write($template, $this->storageRoot);

        $templateDirectory = $this->storageRoot . '/order_confirmation_mail';
        $manifestPath = $templateDirectory . '/manifest.json';
        $manifest = file_get_contents($manifestPath);
        self::assertNotFalse($manifest);
        self::assertJsonStringEqualsJsonString(
            <<<'JSON'
            {
              "schemaVersion": 1,
              "technicalName": "order_confirmation_mail",
              "systemDefault": true,
              "locales": ["de-DE", "en-GB"],
              "nullFields": {
                "de-DE": [],
                "en-GB": []
              }
            }
            JSON,
            $manifest,
        );

        self::assertSame(
            ['.', '..', 'description.txt', 'html.twig', 'plain.twig', 'sender-name.twig', 'subject.twig'],
            scandir($templateDirectory . '/de-DE'),
        );
        self::assertSame('Bestellbestatigung', file_get_contents($templateDirectory . '/de-DE/subject.twig'));
        self::assertSame('Skyy Shop', file_get_contents($templateDirectory . '/de-DE/sender-name.twig'));
        self::assertSame('Bestellung erhalten', file_get_contents($templateDirectory . '/de-DE/description.txt'));
        self::assertSame('<p>Danke</p>', file_get_contents($templateDirectory . '/de-DE/html.twig'));
        self::assertSame('Danke', file_get_contents($templateDirectory . '/de-DE/plain.twig'));
        self::assertDirectoryDoesNotExist($templateDirectory . '/translations');
        self::assertTrue($storage->exists('order_confirmation_mail', $this->storageRoot));

        $contents = file_get_contents($manifestPath);
        self::assertNotFalse($contents);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i', $contents);

        $restored = $storage->read('order_confirmation_mail', $this->storageRoot);

        self::assertSame('order_confirmation_mail', $restored->technicalName);
        self::assertSame(['de-DE', 'en-GB'], array_column($restored->translations, 'locale'));
        self::assertSame('Bestellbestatigung', $restored->translations[0]->subject);
        self::assertSame('Order confirmation', $restored->translations[1]->subject);
        self::assertSame(['order_confirmation_mail'], $storage->discover($this->storageRoot));
    }

    public function testRoundTripPreservesNullFieldsSeparatelyFromEmptyFiles(): void
    {
        $storage = new FilesystemTemplateStorage();
        $template = new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', null, '', null, '<p>Body</p>', null),
        ]);

        $storage->write($template, $this->storageRoot);

        $templateDirectory = $this->storageRoot . '/order_confirmation_mail';
        $manifest = json_decode((string) file_get_contents($templateDirectory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['senderName', 'description', 'contentPlain'],
            $manifest['nullFields']['en-GB'],
        );
        self::assertSame('', file_get_contents($templateDirectory . '/en-GB/sender-name.twig'));
        self::assertSame('', file_get_contents($templateDirectory . '/en-GB/subject.twig'));
        self::assertSame('', file_get_contents($templateDirectory . '/en-GB/description.txt'));
        self::assertSame('', file_get_contents($templateDirectory . '/en-GB/plain.twig'));

        $restored = $storage->read('order_confirmation_mail', $this->storageRoot)->translations[0];
        self::assertNull($restored->senderName);
        self::assertSame('', $restored->subject);
        self::assertNull($restored->description);
        self::assertSame('<p>Body</p>', $restored->contentHtml);
        self::assertNull($restored->contentPlain);
    }

    public function testRepeatedWritesReplaceEveryFileWithoutTemporaryResidue(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write($this->templateWithSubjects('Initial English subject', 'Initial German subject'), $this->storageRoot);
        $storage->write($this->templateWithSubjects('Updated English subject', 'Updated German subject'), $this->storageRoot);

        $templateDirectory = $this->storageRoot . '/order_confirmation_mail';
        $manifest = json_decode((string) file_get_contents($templateDirectory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $restored = $storage->read('order_confirmation_mail', $this->storageRoot);

        self::assertSame(['de-DE', 'en-GB'], $manifest['locales']);
        self::assertSame('Updated German subject', file_get_contents($templateDirectory . '/de-DE/subject.twig'));
        self::assertSame('Updated English subject', file_get_contents($templateDirectory . '/en-GB/subject.twig'));
        self::assertSame('Updated German subject', $restored->translations[0]->subject);
        self::assertSame('Updated English subject', $restored->translations[1]->subject);
        self::assertSame([], glob($templateDirectory . '/.tmp-*') ?: []);
        self::assertSame([], glob($templateDirectory . '/*/.tmp-*') ?: []);
    }

    public function testWriteRemovesLocaleDirectoriesThatAreAbsentFromReplacementTemplate(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write($this->templateWithSubjects('English', 'Deutsch'), $this->storageRoot);

        $storage->write($this->templateForLocale('en-GB', 'English'), $this->storageRoot);

        $templateDirectory = $this->storageRoot . '/order_confirmation_mail';
        self::assertDirectoryDoesNotExist($templateDirectory . '/de-DE');
        self::assertSame(['.', '..', 'en-GB', 'manifest.json'], scandir($templateDirectory));
        self::assertSame(
            ['en-GB'],
            array_column($storage->read('order_confirmation_mail', $this->storageRoot)->translations, 'locale'),
        );
    }

    public function testReadRejectsMissingRequiredLocaleFile(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write($this->templateForLocale('en-GB', 'Order confirmation'), $this->storageRoot);
        unlink($this->storageRoot . '/order_confirmation_mail/en-GB/plain.twig');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('plain.twig');

        $storage->read('order_confirmation_mail', $this->storageRoot);
    }

    public function testReadRejectsMissingManifestWithoutEmittingPhpWarning(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write($this->templateForLocale('en-GB', 'Order confirmation'), $this->storageRoot);
        unlink($this->storageRoot . '/order_confirmation_mail/manifest.json');
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        });

        try {
            $caught = null;
            try {
                $storage->read('order_confirmation_mail', $this->storageRoot);
            } catch (SyncException $exception) {
                $caught = $exception;
            }
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertInstanceOf(SyncException::class, $caught);
        self::assertStringContainsString('manifest.json', $caught->getMessage());
    }

    public function testReadRejectsNonEmptyFileDeclaredNull(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write(new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', null, 'Subject', 'Description', '<p>Body</p>', 'Body'),
        ]), $this->storageRoot);
        file_put_contents($this->storageRoot . '/order_confirmation_mail/en-GB/sender-name.twig', 'Unexpected value');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('senderName');

        $storage->read('order_confirmation_mail', $this->storageRoot);
    }

    public function testReadRejectsManifestWithEnvironmentSpecificIdMetadata(): void
    {
        $storage = new FilesystemTemplateStorage();
        $storage->write($this->templateForLocale('en-GB', 'Order confirmation'), $this->storageRoot);
        $manifestPath = $this->storageRoot . '/order_confirmation_mail/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['mailTemplateId'] = '018f4dc67f7d7bc0b240c1a5088f1e2a';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported top-level keys');

        $storage->read('order_confirmation_mail', $this->storageRoot);
    }

    #[DataProvider('reservedTechnicalNames')]
    public function testReadRejectsReservedTechnicalNames(string $technicalName): void
    {
        $storage = new FilesystemTemplateStorage();

        $this->expectException(InvalidArgumentException::class);

        $storage->read($technicalName, $this->storageRoot);
    }

    #[DataProvider('reservedTechnicalNames')]
    public function testWriteRejectsReservedTechnicalNames(string $technicalName): void
    {
        $storage = new FilesystemTemplateStorage();

        $this->expectException(InvalidArgumentException::class);

        $storage->write($this->invalidTemplate($technicalName), $this->storageRoot);
    }

    public function testRejectsTraversalTechnicalNames(): void
    {
        $storage = new FilesystemTemplateStorage();

        $this->expectException(InvalidArgumentException::class);

        $storage->read('../outside', $this->storageRoot);
    }

    public function testRejectsMalformedLocales(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailTemplateTranslation('de', 'Skyy Shop', 'Subject', 'Description', '<p>Content</p>', 'Content');
    }

    public function testDiscoverRejectsMissingRoot(): void
    {
        $storage = new FilesystemTemplateStorage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $storage->discover($this->storageRoot . '/missing');
    }

    public function testDiscoverRejectsEmptyRoot(): void
    {
        $storage = new FilesystemTemplateStorage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain any mail template bundles');

        $storage->discover($this->storageRoot);
    }

    public function testDiscoverRejectsInvalidCandidateDirectoryName(): void
    {
        mkdir($this->storageRoot . '/invalid name');
        file_put_contents($this->storageRoot . '/invalid name/manifest.json', '{}');
        $storage = new FilesystemTemplateStorage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid technical name');

        $storage->discover($this->storageRoot);
    }

    public function testDiscoverRejectsCandidateDirectoryWithoutManifest(): void
    {
        mkdir($this->storageRoot . '/incomplete_template');
        $storage = new FilesystemTemplateStorage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest.json');

        $storage->discover($this->storageRoot);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedTechnicalNames(): iterable
    {
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
    }

    private function templateForLocale(string $locale, string $subject): MailTemplate
    {
        return new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation($locale, 'Skyy Shop', $subject, 'Order received', '<p>Thank you</p>', 'Thank you'),
        ]);
    }

    private function templateWithSubjects(string $englishSubject, string $germanSubject): MailTemplate
    {
        return new MailTemplate('order_confirmation_mail', [
            new MailTemplateTranslation('en-GB', 'Skyy Shop', $englishSubject, 'Order received', '<p>Thank you</p>', 'Thank you'),
            new MailTemplateTranslation('de-DE', 'Skyy Shop', $germanSubject, 'Bestellung erhalten', '<p>Danke</p>', 'Danke'),
        ]);
    }

    private function invalidTemplate(string $technicalName): MailTemplate
    {
        $reflection = new ReflectionClass(MailTemplate::class);
        /** @var MailTemplate $template */
        $template = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('technicalName')->setValue($template, $technicalName);
        $reflection->getProperty('translations')->setValue($template, []);

        return $template;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertNotFalse($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
