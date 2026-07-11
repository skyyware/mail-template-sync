<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginMetadataTest extends TestCase
{
    public function testPluginMetadataMatchesThePublishedPackageContract(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $composerPath = $projectRoot . '/composer.json';

        self::assertFileExists($composerPath);

        $composerJson = file_get_contents($composerPath);
        self::assertNotFalse($composerJson);

        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('skyyware/mail-template-sync', $composer['name']);
        self::assertSame('shopware-platform-plugin', $composer['type']);
        self::assertSame('MIT', $composer['license']);
        self::assertSame('~6.6.0 || ~6.7.0', $composer['require']['shopware/core']);
        self::assertSame(
            'Skyyware\\SkyyMailTemplateSync\\SkyyMailTemplateSync',
            $composer['extra']['shopware-plugin-class'],
        );
        self::assertSame(
            'src/',
            $composer['autoload']['psr-4']['Skyyware\\SkyyMailTemplateSync\\'],
        );
        self::assertSame(
            [
                'en-GB' => 'Skyy Mail Template Sync',
                'de-DE' => 'Skyy Mail Template Sync',
            ],
            $composer['extra']['label'],
        );
        self::assertSame('https://www.skyyware.com/', $composer['extra']['manufacturerLink']['en-GB']);
        self::assertSame('https://www.skyyware.com/contact/', $composer['extra']['supportLink']['en-GB']);
        self::assertFileExists($projectRoot . '/' . $composer['extra']['plugin-icon']);

        self::assertTrue(class_exists($composer['extra']['shopware-plugin-class']));
    }

    public function testBackupRetentionConfigurationDisallowsNegativeValues(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 2) . '/src/Resources/config/config.xml');
        self::assertNotFalse($config);
        $fields = $config->xpath('//input-field[name="backupRetentionDays"]');
        self::assertIsArray($fields);
        self::assertCount(1, $fields);

        self::assertSame('0', (string) $fields[0]->min);
    }
}
