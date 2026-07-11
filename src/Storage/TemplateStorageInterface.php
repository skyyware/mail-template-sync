<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Storage;

use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;

interface TemplateStorageInterface
{
    public function exists(string $technicalName, string $root): bool;

    public function read(string $technicalName, string $root): MailTemplate;

    public function write(MailTemplate $template, string $root): void;

    /**
     * @return list<string>
     */
    public function discover(string $root): array;
}
