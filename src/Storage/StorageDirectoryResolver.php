<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Storage;

use InvalidArgumentException;

final readonly class StorageDirectoryResolver
{
    private string $pluginRoot;

    private string $projectRoot;

    private string $vendorRoot;

    public function __construct(string $projectRoot, ?string $pluginRoot = null)
    {
        $canonicalProjectRoot = realpath($projectRoot);
        if ($canonicalProjectRoot === false || !is_dir($canonicalProjectRoot)) {
            throw new InvalidArgumentException('The Shopware project root must be an existing directory.');
        }

        $this->projectRoot = $this->normalizeAbsolutePath($canonicalProjectRoot);
        $this->pluginRoot = $this->canonicalizeDirectory($pluginRoot ?? dirname(__DIR__, 2));
        $this->vendorRoot = $this->canonicalizeDirectory($this->projectRoot . '/vendor', false);
    }

    public function resolveConfigured(string $directory): string
    {
        if ($this->isAbsolutePath($directory)) {
            throw new InvalidArgumentException('Configured storage directory must be project-relative.');
        }

        return $this->resolveProjectRelative($directory);
    }

    public function resolveOverride(string $directory): string
    {
        if (!$this->isAbsolutePath($directory)) {
            return $this->resolveProjectRelative($directory);
        }

        if ($this->isForeignAbsolutePath($directory)) {
            throw new InvalidArgumentException('Windows path syntax is not supported on Unix.');
        }

        $lexicalPath = $this->normalizeAbsolutePath($directory);
        $resolvedPath = $this->canonicalizeDirectory($lexicalPath, false);
        $mustStayInProject = $this->isWithin($lexicalPath, $this->projectRoot);
        $this->assertAllowed($resolvedPath, $mustStayInProject);

        return $resolvedPath;
    }

    private function resolveProjectRelative(string $directory): string
    {
        if ($directory === '') {
            throw new InvalidArgumentException('The storage directory must not be empty.');
        }

        $relativeDirectory = $this->normalizeRelativeDirectory($directory);
        $candidate = $relativeDirectory === ''
            ? $this->projectRoot
            : $this->projectRoot . '/' . $relativeDirectory;
        $resolvedPath = $this->canonicalizeDirectory($candidate, false);
        $this->assertAllowed($resolvedPath, true);

        return $resolvedPath;
    }

    private function assertAllowed(string $path, bool $mustStayInProject): void
    {
        if ($mustStayInProject && !$this->isWithin($path, $this->projectRoot)) {
            throw new InvalidArgumentException('The storage directory must stay within the project root after resolving symbolic links.');
        }

        if ($path === $this->projectRoot
            || $this->isWithin($path, $this->vendorRoot)
            || $this->isWithin($path, $this->pluginRoot)) {
            throw new InvalidArgumentException('The storage directory points into a protected project or package root.');
        }
    }

    private function canonicalizeDirectory(string $path, bool $mustExist = true): string
    {
        $normalizedPath = $this->normalizeAbsolutePath($path);
        $probe = $normalizedPath;
        $suffix = [];

        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new InvalidArgumentException('The storage directory has no resolvable existing ancestor.');
            }

            array_unshift($suffix, basename($probe));
            $probe = $parent;
        }

        if ($mustExist && $suffix !== []) {
            throw new InvalidArgumentException('The configured package root must be an existing directory.');
        }

        if (!is_dir($probe)) {
            throw new InvalidArgumentException('The storage directory or its nearest existing ancestor must be a directory.');
        }

        $canonicalAncestor = realpath($probe);
        if ($canonicalAncestor === false) {
            throw new InvalidArgumentException('The storage directory contains an unresolved symbolic link.');
        }

        $resolvedPath = $this->normalizeAbsolutePath($canonicalAncestor);
        if ($suffix !== []) {
            $resolvedPath = ($resolvedPath === '/' ? '' : $resolvedPath) . '/' . implode('/', $suffix);
        }

        return $resolvedPath;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isForeignAbsolutePath(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '/'
            && (str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '/';

        if (preg_match('/^(?<drive>[A-Za-z]:)(?:\/|$)/', $path, $matches) === 1) {
            $prefix = $matches['drive'] . '/';
            $path = substr($path, 2);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
        }

        $segments = [];
        foreach (preg_split('#/+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return rtrim($prefix . implode('/', $segments), '/') ?: '/';
    }

    private function normalizeRelativeDirectory(string $directory): string
    {
        $segments = [];
        foreach (preg_split('/[\\\\\/]+/', $directory) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new InvalidArgumentException('The storage directory must stay within the project root.');
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim($path, '/');
        $root = rtrim($root, '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
