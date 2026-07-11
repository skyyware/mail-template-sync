<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Tests\Unit\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skyyware\SkyyMailTemplateSync\Storage\StorageDirectoryResolver;

final class StorageDirectoryResolverTest extends TestCase
{
    private string $outsideRoot;

    private string $pluginRoot;

    private string $projectRoot;

    protected function setUp(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'skyy-mail-template-resolver-');
        self::assertNotFalse($base);
        unlink($base);
        mkdir($base);

        $this->projectRoot = $base . '/project';
        $this->outsideRoot = $base . '/outside';
        $this->pluginRoot = $this->projectRoot . '/custom/plugins/SkyyMailTemplateSync';
        mkdir($this->pluginRoot, 0777, true);
        mkdir($this->projectRoot . '/vendor', 0777, true);
        mkdir($this->outsideRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->projectRoot));
    }

    public function testResolvesConfiguredRelativeDirectoryFromProjectRoot(): void
    {
        self::assertSame(
            $this->projectRoot . '/custom/mail-templates',
            $this->resolver()->resolveConfigured('custom/mail-templates'),
        );
    }

    public function testResolvesRelativeCliOverrideFromProjectRoot(): void
    {
        self::assertSame(
            $this->projectRoot . '/exports/mail-templates',
            $this->resolver()->resolveOverride('exports/mail-templates'),
        );
    }

    public function testRejectsAbsoluteConfiguredDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured storage directory must be project-relative.');

        $this->resolver()->resolveConfigured($this->outsideRoot . '/mail-templates');
    }

    public function testAllowsAndCanonicalizesExplicitAbsoluteCliDirectory(): void
    {
        self::assertSame(
            (string) realpath($this->outsideRoot) . '/mail-templates',
            $this->resolver()->resolveOverride($this->outsideRoot . '/mail-templates'),
        );
    }

    public function testCanonicalizesExistingSymlinkAncestorsWithinProject(): void
    {
        mkdir($this->projectRoot . '/data/templates', 0777, true);
        symlink($this->projectRoot . '/data/templates', $this->projectRoot . '/template-link');

        self::assertSame(
            $this->projectRoot . '/data/templates/mail',
            $this->resolver()->resolveConfigured('template-link/mail'),
        );
        self::assertSame(
            $this->projectRoot . '/data/templates/mail',
            $this->resolver()->resolveOverride('template-link/mail'),
        );
    }

    public function testConfiguredDirectoryRejectsSymlinkEscape(): void
    {
        symlink($this->outsideRoot, $this->projectRoot . '/outside-link');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay within the project root');

        $this->resolver()->resolveConfigured('outside-link/mail');
    }

    public function testRelativeCliOverrideRejectsSymlinkEscape(): void
    {
        symlink($this->outsideRoot, $this->projectRoot . '/outside-link');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay within the project root');

        $this->resolver()->resolveOverride('outside-link/mail');
    }

    public function testAbsoluteCliOverrideUnderProjectRejectsSymlinkEscape(): void
    {
        symlink($this->outsideRoot, $this->projectRoot . '/outside-link');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay within the project root');

        $this->resolver()->resolveOverride($this->projectRoot . '/outside-link/mail');
    }

    #[DataProvider('projectRootDirectories')]
    public function testRejectsProjectRootAsStorageDirectory(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protected project or package root');

        $source === 'configured'
            ? $this->resolver()->resolveConfigured('.')
            : $this->resolver()->resolveOverride($this->projectRoot);
    }

    #[DataProvider('directorySources')]
    public function testRejectsProjectVendorDirectoryForBothSources(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protected project or package root');

        $source === 'configured'
            ? $this->resolver()->resolveConfigured('vendor/mail-templates')
            : $this->resolver()->resolveOverride($this->projectRoot . '/vendor/mail-templates');
    }

    #[DataProvider('directorySources')]
    public function testRejectsPluginPackageDirectoryForBothSources(string $source): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protected project or package root');

        $source === 'configured'
            ? $this->resolver()->resolveConfigured('custom/plugins/SkyyMailTemplateSync/mail-templates')
            : $this->resolver()->resolveOverride($this->pluginRoot . '/mail-templates');
    }

    public function testRejectsSymlinkIntoPluginPackageRoot(): void
    {
        symlink($this->pluginRoot, $this->projectRoot . '/plugin-link');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protected project or package root');

        $this->resolver()->resolveConfigured('plugin-link/mail-templates');
    }

    #[DataProvider('escapingRelativeDirectories')]
    public function testRejectsRelativeDirectoriesThatEscapeProjectRoot(string $directory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay within the project root');

        $this->resolver()->resolveConfigured($directory);
    }

    public function testNormalizesRelativeTraversalThatStaysWithinProjectRoot(): void
    {
        self::assertSame(
            $this->projectRoot . '/custom/mail-templates',
            $this->resolver()->resolveConfigured('custom/cache/../mail-templates'),
        );
    }

    public function testRejectsExistingFileAsStorageDirectory(): void
    {
        file_put_contents($this->projectRoot . '/not-a-directory', 'file');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a directory');

        $this->resolver()->resolveConfigured('not-a-directory');
    }

    #[DataProvider('windowsAbsoluteDirectories')]
    public function testConfiguredDirectoryRejectsWindowsAbsoluteSyntax(string $directory): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configured storage directory must be project-relative.');

        $this->resolver()->resolveConfigured($directory);
    }

    #[DataProvider('windowsAbsoluteDirectories')]
    public function testUnixRejectsWindowsAbsoluteCliSyntax(string $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows path syntax is native on Windows.');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Windows path syntax is not supported on Unix.');

        $this->resolver()->resolveOverride($directory);
    }

    #[DataProvider('windowsAbsoluteDirectories')]
    public function testWindowsHandlesNativeAbsoluteSyntax(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Native Windows path handling is only executable on Windows.');
        }

        try {
            self::assertNotSame('', $this->resolver()->resolveOverride($directory));
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('not supported on Unix', $exception->getMessage());
        }
    }


    /**
     * @return iterable<string, array{string}>
     */
    public static function directorySources(): iterable
    {
        yield 'configured directory' => ['configured'];
        yield 'CLI override' => ['override'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function projectRootDirectories(): iterable
    {
        yield 'configured project root' => ['configured'];
        yield 'absolute CLI project root' => ['override'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingRelativeDirectories(): iterable
    {
        yield 'leading traversal' => ['../outside'];
        yield 'nested traversal' => ['custom/mail-templates/../../../outside'];
        yield 'mixed separators' => ['custom\\mail-templates\\..\\..\\..\\outside'];
        yield 'project-prefix collision' => ['../project-backup'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function windowsAbsoluteDirectories(): iterable
    {
        yield 'UNC path' => ['\\\\server\\share\\templates'];
        yield 'root-relative path' => ['\\templates'];
        yield 'drive-qualified path' => ['C:\\templates'];
    }

    private function resolver(): StorageDirectoryResolver
    {
        return new StorageDirectoryResolver($this->projectRoot, $this->pluginRoot);
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
