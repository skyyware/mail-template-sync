<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleaseToolingTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
    }

    public function testComposerAllowsOnlyTheCurrentShopwareReleaseLine(): void
    {
        $metadata = json_decode(
            (string) file_get_contents($this->projectRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($metadata['require'] as $package => $constraint) {
            if (!str_starts_with($package, 'shopware/')) {
                continue;
            }

            self::assertTrue(\Composer\Semver\Semver::satisfies('6.7.15.0', $constraint));
            self::assertFalse(\Composer\Semver\Semver::satisfies('6.6.10.27', $constraint));
            self::assertFalse(\Composer\Semver\Semver::satisfies('6.8.0.0', $constraint));
        }
    }

    public function testShopwareTranslatableLinksUseLocaleMaps(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->projectRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([
            'en-GB' => 'https://www.skyyware.com/',
            'de-DE' => 'https://www.skyyware.com/',
        ], $composer['extra']['manufacturerLink']);
        self::assertSame([
            'en-GB' => 'https://www.skyyware.com/contact/',
            'de-DE' => 'https://www.skyyware.com/contact/',
        ], $composer['extra']['supportLink']);
    }

    public function testCheckUsesNormalComposerPublishValidation(): void
    {
        $check = (string) file_get_contents($this->projectRoot . '/bin/check');

        self::assertStringContainsString('composer validate --strict', $check);
        self::assertStringNotContainsString('--no-check-publish', $check);
    }

    public function testGeneratedArtifactsReportsAndScratchPathsAreIgnored(): void
    {
        $gitignore = (string) file_get_contents($this->projectRoot . '/.gitignore');

        self::assertStringContainsString('/.php-cs-fixer.cache', $gitignore);
        self::assertStringContainsString('/build/', $gitignore);
        self::assertStringContainsString('/reports/', $gitignore);
        self::assertStringContainsString('/scratch/', $gitignore);
        self::assertStringContainsString('/.superpowers/', $gitignore);
    }

    public function testPackagedComposerMetadataCarriesTheReleaseVersion(): void
    {
        $output = [];
        $exitCode = 1;
        exec(
            'VERSION=0.2.1 ' . escapeshellarg($this->projectRoot . '/bin/package') . ' 2>&1',
            $output,
            $exitCode,
        );
        self::assertSame(0, $exitCode, implode("\n", $output));

        $archive = new ZipArchive();
        self::assertTrue(
            $archive->open($this->projectRoot . '/build/SkyyMailTemplateSync-0.2.1.zip') === true,
        );
        $composerJson = $archive->getFromName('SkyyMailTemplateSync/composer.json');
        $archive->close();
        self::assertIsString($composerJson);

        $metadata = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.2.1', $metadata['version'] ?? null);
    }

    public function testReadmeDocumentsFiveFileLayoutAndNullableMetadata(): void
    {
        $readme = (string) file_get_contents($this->projectRoot . '/README.md');

        foreach (['subject.twig', 'sender-name.twig', 'description.txt', 'html.twig', 'plain.twig', 'nullFields'] as $requiredText) {
            self::assertStringContainsString($requiredText, $readme);
        }
    }
}
