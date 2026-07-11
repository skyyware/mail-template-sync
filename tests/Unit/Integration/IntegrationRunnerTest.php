<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class IntegrationRunnerTest extends TestCase
{
    private string $pluginRoot;

    private string $shopwareRoot;

    private string $temporaryRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'skyy-integration-runner-');
        self::assertNotFalse($path);
        unlink($path);
        mkdir($path);

        $this->temporaryRoot = $path;
        $this->shopwareRoot = $path . '/shopware';
        $this->pluginRoot = dirname(__DIR__, 3);
        mkdir($this->shopwareRoot . '/vendor/bin', 0777, true);
        file_put_contents($this->shopwareRoot . '/vendor/bin/phpunit', 'placeholder');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testCreatesDiscoverableSymlinkAndRemovesDirectoriesItCreated(): void
    {
        $process = $this->runnerProcess(0);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertFileExists($this->temporaryRoot . '/child-ran');
        self::assertFileDoesNotExist($this->pluginLink());
        self::assertDirectoryDoesNotExist($this->shopwareRoot . '/custom');
    }

    public function testRemovesCreatedSymlinkWhenTestProcessFailsAndPreservesExitCode(): void
    {
        $process = $this->runnerProcess(7);

        $process->run();

        self::assertSame(7, $process->getExitCode(), $process->getErrorOutput());
        self::assertFileDoesNotExist($this->pluginLink());
        self::assertDirectoryDoesNotExist($this->shopwareRoot . '/custom');
    }

    public function testFailsWithoutTouchingUnrelatedOccupiedPluginPath(): void
    {
        mkdir($this->pluginLink(), 0777, true);
        file_put_contents($this->pluginLink() . '/sentinel', 'keep');
        $process = $this->runnerProcess(0);

        $process->run();

        self::assertFalse($process->isSuccessful());
        self::assertStringContainsString('occupied by an unrelated target', $process->getErrorOutput());
        self::assertFileExists($this->pluginLink() . '/sentinel');
        self::assertFileDoesNotExist($this->temporaryRoot . '/child-ran');
    }

    public function testLeavesPreExistingSymlinkToThisPluginUntouched(): void
    {
        mkdir(dirname($this->pluginLink()), 0777, true);
        symlink($this->pluginRoot, $this->pluginLink());
        $process = $this->runnerProcess(0);

        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertTrue(is_link($this->pluginLink()));
        self::assertSame($this->pluginRoot, (string) realpath($this->pluginLink()));
    }

    private function runnerProcess(int $childExitCode): Process
    {
        $runner = $this->pluginRoot . '/bin/integration';
        self::assertFileExists($runner);

        $fakePhp = $this->temporaryRoot . '/fake-php';
        file_put_contents($fakePhp, <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            link="$SHOPWARE_PROJECT_ROOT/custom/plugins/SkyyMailTemplateSync"
            [[ -L "$link" ]]
            [[ "$(realpath "$link")" == "$EXPECTED_PLUGIN_ROOT" ]]
            touch "$FAKE_MARKER"
            exit "$FAKE_EXIT_CODE"
            BASH);
        chmod($fakePhp, 0755);

        return new Process([$runner], $this->pluginRoot, [
            'DATABASE_URL' => 'mysql://root@127.0.0.1:33307/skyy_mail_sync',
            'EXPECTED_PLUGIN_ROOT' => $this->pluginRoot,
            'FAKE_EXIT_CODE' => (string) $childExitCode,
            'FAKE_MARKER' => $this->temporaryRoot . '/child-ran',
            'SHOPWARE_PROJECT_ROOT' => $this->shopwareRoot,
            'SKYY_PHP_BINARY' => $fakePhp,
        ]);
    }

    private function pluginLink(): string
    {
        return $this->shopwareRoot . '/custom/plugins/SkyyMailTemplateSync';
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);

            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        self::assertNotFalse($entries);
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
