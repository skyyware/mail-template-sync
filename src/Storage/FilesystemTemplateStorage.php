<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Storage;

use InvalidArgumentException;
use JsonException;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplateTranslation;
use Skyyware\SkyyMailTemplateSync\Exception\SyncException;
use Symfony\Component\Filesystem\Filesystem;

final class FilesystemTemplateStorage implements TemplateStorageInterface
{
    /** @var array<string, string> */
    private const FIELD_FILES = [
        'subject' => 'subject.twig',
        'senderName' => 'sender-name.twig',
        'description' => 'description.txt',
        'contentHtml' => 'html.twig',
        'contentPlain' => 'plain.twig',
    ];

    /** @var list<string> */
    private const MANIFEST_KEYS = [
        'schemaVersion',
        'technicalName',
        'systemDefault',
        'locales',
        'nullFields',
    ];

    private readonly Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    public function exists(string $technicalName, string $root): bool
    {
        $this->assertTechnicalName($technicalName);
        $this->assertNotSymbolicLink($root, 'The storage root must not be a symbolic link.');

        $templateDirectory = $this->templateDirectory($technicalName, $root);
        $this->assertNotSymbolicLink(
            $templateDirectory,
            sprintf('The storage directory for "%s" must not be a symbolic link.', $technicalName),
        );

        $manifestPath = $templateDirectory . '/manifest.json';
        $this->assertNotSymbolicLink(
            $manifestPath,
            sprintf('The manifest for "%s" must not be a symbolic link.', $technicalName),
        );

        return is_file($manifestPath);
    }

    public function read(string $technicalName, string $root): MailTemplate
    {
        $this->assertTechnicalName($technicalName);
        $this->assertNotSymbolicLink($root, 'The storage root must not be a symbolic link.');

        $templateDirectory = $this->templateDirectory($technicalName, $root);
        $this->assertDirectory($templateDirectory, sprintf('The bundle for "%s" does not exist.', $technicalName));
        $this->assertNotSymbolicLink(
            $templateDirectory,
            sprintf('The storage directory for "%s" must not be a symbolic link.', $technicalName),
        );

        $manifestPath = $templateDirectory . '/manifest.json';
        $this->assertNotSymbolicLink(
            $manifestPath,
            sprintf('The manifest for "%s" must not be a symbolic link.', $technicalName),
        );
        $manifest = $this->decodeJsonFile($manifestPath);

        $manifestKeys = array_keys($manifest);
        $allowedManifestKeys = self::MANIFEST_KEYS;
        sort($manifestKeys, SORT_STRING);
        sort($allowedManifestKeys, SORT_STRING);
        if ($manifestKeys !== $allowedManifestKeys) {
            throw new SyncException(sprintf('The manifest for "%s" contains missing or unsupported top-level keys.', $technicalName));
        }

        if (($manifest['schemaVersion'] ?? null) !== 1
            || ($manifest['technicalName'] ?? null) !== $technicalName
            || ($manifest['systemDefault'] ?? null) !== true
            || !is_array($manifest['locales'] ?? null)
            || !is_array($manifest['nullFields'] ?? null)) {
            throw new SyncException(sprintf('The manifest for "%s" is invalid.', $technicalName));
        }

        $locales = $this->validatedLocales($manifest['locales'], $technicalName);
        $nullFields = $this->validatedNullFields($manifest['nullFields'], $locales, $technicalName);
        $translations = [];

        foreach ($locales as $locale) {
            $localeDirectory = $templateDirectory . '/' . $locale;
            $this->assertDirectory(
                $localeDirectory,
                sprintf('Translation directory for "%s" locale "%s" does not exist.', $technicalName, $locale),
            );
            $this->assertNotSymbolicLink(
                $localeDirectory,
                sprintf('Translation directory for "%s" locale "%s" must not be a symbolic link.', $technicalName, $locale),
            );

            $values = [];
            foreach (self::FIELD_FILES as $field => $filename) {
                $path = $localeDirectory . '/' . $filename;
                $this->assertNotSymbolicLink(
                    $path,
                    sprintf('Translation file "%s" for "%s" must not be a symbolic link.', $filename, $technicalName),
                );
                $value = $this->readTextFile($path, $technicalName, $locale, $filename);
                if (in_array($field, $nullFields[$locale], true)) {
                    if ($value !== '') {
                        throw new SyncException(sprintf(
                            'Translation field "%s" for "%s" locale "%s" is declared null but its file is not empty.',
                            $field,
                            $technicalName,
                            $locale,
                        ));
                    }

                    $values[$field] = null;
                } else {
                    $values[$field] = $value;
                }
            }

            $this->assertDirectoryEntries(
                $localeDirectory,
                array_values(self::FIELD_FILES),
                sprintf('Translation directory for "%s" locale "%s"', $technicalName, $locale),
            );

            $translations[] = new MailTemplateTranslation(
                $locale,
                $values['senderName'],
                $values['subject'],
                $values['description'],
                $values['contentHtml'],
                $values['contentPlain'],
            );
        }

        $this->assertDirectoryEntries(
            $templateDirectory,
            ['manifest.json', ...$locales],
            sprintf('Bundle directory for "%s"', $technicalName),
        );

        return new MailTemplate($technicalName, $translations);
    }

    public function write(MailTemplate $template, string $root): void
    {
        $this->assertTechnicalName($template->technicalName);
        $this->assertNotSymbolicLink($root, 'The storage root must not be a symbolic link.');
        $this->filesystem->mkdir($root);

        $templateDirectory = $this->templateDirectory($template->technicalName, $root);
        $this->assertNotSymbolicLink(
            $templateDirectory,
            sprintf('The storage directory for "%s" must not be a symbolic link.', $template->technicalName),
        );
        $this->filesystem->mkdir($templateDirectory);

        $translations = $template->translations;
        usort(
            $translations,
            static fn(MailTemplateTranslation $left, MailTemplateTranslation $right): int => $left->locale <=> $right->locale,
        );

        $nullFields = [];
        foreach ($translations as $translation) {
            $localeDirectory = $templateDirectory . '/' . $translation->locale;
            $this->assertNotSymbolicLink(
                $localeDirectory,
                sprintf(
                    'Translation directory for "%s" locale "%s" must not be a symbolic link.',
                    $template->technicalName,
                    $translation->locale,
                ),
            );
            $this->filesystem->mkdir($localeDirectory);

            $localeNullFields = [];
            foreach (self::FIELD_FILES as $field => $filename) {
                $path = $localeDirectory . '/' . $filename;
                $this->assertNotSymbolicLink(
                    $path,
                    sprintf('Translation file "%s" for "%s" must not be a symbolic link.', $filename, $template->technicalName),
                );
                $value = $translation->{$field};
                if ($value === null) {
                    $localeNullFields[] = $field;
                }

                $this->filesystem->dumpFile($path, $value ?? '');
            }

            $nullFields[$translation->locale] = $localeNullFields;
            $this->removeUnexpectedEntries($localeDirectory, array_values(self::FIELD_FILES));
        }

        $locales = array_map(
            static fn(MailTemplateTranslation $translation): string => $translation->locale,
            $translations,
        );
        $this->removeUnexpectedEntries($templateDirectory, ['manifest.json', ...$locales]);

        $this->filesystem->dumpFile(
            $templateDirectory . '/manifest.json',
            $this->encodeJson([
                'schemaVersion' => 1,
                'technicalName' => $template->technicalName,
                'systemDefault' => true,
                'locales' => $locales,
                'nullFields' => $nullFields === [] ? (object) [] : $nullFields,
            ]),
        );
    }

    public function discover(string $root): array
    {
        if (!file_exists($root)) {
            throw new SyncException(sprintf('The storage root "%s" does not exist.', $root));
        }

        if (!is_dir($root)) {
            throw new SyncException(sprintf('The storage root "%s" is not a directory.', $root));
        }

        $this->assertNotSymbolicLink($root, 'The storage root must not be a symbolic link.');
        $entries = scandir($root);
        if ($entries === false) {
            throw new SyncException(sprintf('Unable to list the storage root "%s".', $root));
        }

        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        if ($entries === []) {
            throw new SyncException(sprintf('The storage root "%s" does not contain any mail template bundles.', $root));
        }

        $technicalNames = [];
        foreach ($entries as $entry) {
            $candidate = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($candidate) || is_link($candidate)) {
                throw new SyncException(sprintf('Storage entry "%s" is not a regular template directory.', $entry));
            }

            if (!MailTemplate::isValidTechnicalName($entry)) {
                throw new SyncException(sprintf('Storage candidate "%s" has an invalid technical name.', $entry));
            }

            $manifestPath = $candidate . '/manifest.json';
            if (!is_file($manifestPath) || is_link($manifestPath)) {
                throw new SyncException(sprintf('Storage candidate "%s" does not contain a regular manifest.json.', $entry));
            }

            $technicalNames[] = $entry;
        }

        return $technicalNames;
    }

    private function assertTechnicalName(string $technicalName): void
    {
        if (!MailTemplate::isValidTechnicalName($technicalName)) {
            throw new InvalidArgumentException('The technical name contains unsupported characters.');
        }
    }

    private function templateDirectory(string $technicalName, string $root): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $technicalName;
    }

    /**
     * @param array<mixed> $locales
     *
     * @return list<string>
     */
    private function validatedLocales(array $locales, string $technicalName): array
    {
        $validated = [];
        foreach ($locales as $locale) {
            if (!is_string($locale) || !MailTemplateTranslation::isValidLocale($locale)) {
                throw new SyncException(sprintf('The manifest for "%s" contains an invalid locale.', $technicalName));
            }

            if (in_array($locale, $validated, true)) {
                throw new SyncException(sprintf('The manifest for "%s" contains duplicate locales.', $technicalName));
            }

            $validated[] = $locale;
        }

        $sorted = $validated;
        sort($sorted, SORT_STRING);
        if ($validated !== $sorted) {
            throw new SyncException(sprintf('The manifest locales for "%s" must be sorted.', $technicalName));
        }

        return $validated;
    }

    /**
     * @param array<mixed> $nullFields
     * @param list<string> $locales
     *
     * @return array<string, list<string>>
     */
    private function validatedNullFields(array $nullFields, array $locales, string $technicalName): array
    {
        if (array_keys($nullFields) !== $locales) {
            throw new SyncException(sprintf('The null-field metadata for "%s" must match its locales.', $technicalName));
        }

        $fieldOrder = array_keys(self::FIELD_FILES);
        $validated = [];
        foreach ($locales as $locale) {
            $fields = $nullFields[$locale];
            if (!is_array($fields)) {
                throw new SyncException(sprintf('The null-field metadata for "%s" locale "%s" is invalid.', $technicalName, $locale));
            }

            $expectedOrder = [];
            foreach ($fieldOrder as $field) {
                if (in_array($field, $fields, true)) {
                    $expectedOrder[] = $field;
                }
            }

            if ($fields !== $expectedOrder) {
                throw new SyncException(sprintf('The null-field metadata for "%s" locale "%s" is invalid.', $technicalName, $locale));
            }

            $validated[$locale] = $expectedOrder;
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new SyncException(sprintf('Storage file "%s" does not exist or is not a regular file.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new SyncException(sprintf('Unable to read storage file "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SyncException(sprintf('Storage file "%s" contains invalid JSON.', $path), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new SyncException(sprintf('Storage file "%s" must contain a JSON object.', $path));
        }

        return $decoded;
    }

    private function readTextFile(string $path, string $technicalName, string $locale, string $filename): string
    {
        if (!is_file($path)) {
            throw new SyncException(sprintf(
                'Unable to read required file "%s" for "%s" locale "%s".',
                $filename,
                $technicalName,
                $locale,
            ));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new SyncException(sprintf(
                'Unable to read required file "%s" for "%s" locale "%s".',
                $filename,
                $technicalName,
                $locale,
            ));
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function encodeJson(array $values): string
    {
        try {
            return json_encode(
                $values,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
        } catch (JsonException $exception) {
            throw new SyncException('Unable to encode template storage JSON.', 0, $exception);
        }
    }

    private function assertDirectory(string $path, string $message): void
    {
        if (!is_dir($path)) {
            throw new SyncException($message);
        }
    }

    private function assertNotSymbolicLink(string $path, string $message): void
    {
        if (is_link($path)) {
            throw new SyncException($message);
        }
    }

    /**
     * @param list<string> $expectedEntries
     */
    private function assertDirectoryEntries(string $directory, array $expectedEntries, string $label): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new SyncException(sprintf('Unable to list %s.', $label));
        }

        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries, SORT_STRING);
        sort($expectedEntries, SORT_STRING);
        if ($entries !== $expectedEntries) {
            throw new SyncException(sprintf('%s contains unsupported or missing files.', $label));
        }
    }

    /**
     * @param list<string> $expectedEntries
     */
    private function removeUnexpectedEntries(string $directory, array $expectedEntries): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new SyncException(sprintf('Unable to list storage directory "%s".', $directory));
        }

        $expected = array_fill_keys($expectedEntries, true);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($expected[$entry])) {
                continue;
            }

            $this->filesystem->remove($directory . '/' . $entry);
        }
    }
}
