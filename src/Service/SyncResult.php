<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Service;

final readonly class SyncResult
{
    /**
     * @param list<string> $processedNames
     * @param array<string, list<string>> $changedFields
     */
    public function __construct(
        public array $processedNames,
        public array $changedFields,
    ) {}
}
