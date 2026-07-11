<?php

declare(strict_types=1);

namespace Skyyware\SkyyMailTemplateSync\Repository;

use Shopware\Core\Framework\Context;
use Skyyware\SkyyMailTemplateSync\Domain\MailTemplate;

interface MailTemplateRepositoryInterface
{
    /**
     * @return list<string>
     */
    public function listTechnicalNames(Context $context): array;

    public function lockSystemDefault(string $technicalName): void;

    public function fetch(string $technicalName, Context $context): MailTemplate;

    public function validate(MailTemplate $template, Context $context): void;

    public function update(MailTemplate $template, Context $context): void;
}
