<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseToolingTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
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

    public function testCiPinsActionsAndRunsRealIntegrationForBothShopwareLanes(): void
    {
        $workflow = (string) file_get_contents($this->projectRoot . '/.github/workflows/ci.yml');
        preg_match_all('/^\s*-\s+uses:\s+[^@\s]+@([^\s#]+)/m', $workflow, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $reference) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $reference);
        }

        self::assertStringContainsString('shopware: "~6.6.0"', $workflow);
        self::assertStringContainsString('shopware: "~6.7.0"', $workflow);
        self::assertStringContainsString('integration_shopware: "6.6.0.0"', $workflow);
        self::assertStringContainsString('expected_core_version: "6.6.0.0"', $workflow);
        self::assertStringContainsString('allow_insecure_fixture: "1"', $workflow);
        self::assertStringContainsString('allow_insecure_fixture: "0"', $workflow);
        self::assertStringContainsString('flags+=(--prefer-lowest)', $workflow);
        self::assertStringContainsString('mariadb:', $workflow);
        self::assertStringContainsString(
            'echo "SHOPWARE_PROJECT_ROOT=$RUNNER_TEMP/shopware" >> "$GITHUB_ENV"',
            $workflow,
        );
        self::assertStringContainsString(
            'if [[ "$ALLOW_INSECURE_FIXTURE" == "1" ]]',
            $workflow,
        );
        self::assertStringContainsString('fixture_flags+=(--no-blocking)', $workflow);
        self::assertStringContainsString(
            'composer create-project shopware/production "$SHOPWARE_PROJECT_ROOT" "$INTEGRATION_SHOPWARE_VERSION" "${fixture_flags[@]}"',
            $workflow,
        );
        self::assertStringContainsString('composer --working-dir="$SHOPWARE_PROJECT_ROOT" require', $workflow);
        self::assertStringContainsString('EXPECTED_SHOPWARE_CORE_VERSION: ${{ matrix.expected_core_version }}', $workflow);
        self::assertStringContainsString(
            'COMPOSER_HOME="$RUNNER_TEMP/shopware-composer-home" bin/integration',
            $workflow,
        );
        self::assertStringNotContainsString('COMPOSER_NO_BLOCKING:', $workflow);
        self::assertStringNotContainsString('SHOPWARE_PROJECT_ROOT: ${{ github.workspace }}', $workflow);
        self::assertStringNotContainsString('SHOPWARE_PROJECT_ROOT: ${{ runner.temp }}', $workflow);

        $integrationTest = (string) file_get_contents(
            $this->projectRoot . '/tests/Integration/ShopwareIntegrationTest.php',
        );
        self::assertStringContainsString('InstalledVersions::getPrettyVersion', $integrationTest);
        self::assertStringContainsString('EXPECTED_SHOPWARE_CORE_VERSION', $integrationTest);
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

    public function testReadmeDocumentsFiveFileLayoutAndNullableMetadata(): void
    {
        $readme = (string) file_get_contents($this->projectRoot . '/README.md');

        foreach (['subject.twig', 'sender-name.twig', 'description.txt', 'html.twig', 'plain.twig', 'nullFields'] as $requiredText) {
            self::assertStringContainsString($requiredText, $readme);
        }
    }
}
