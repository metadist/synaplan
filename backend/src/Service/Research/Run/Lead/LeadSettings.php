<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

/**
 * Which lead mode an install asked for, and which models may use tools.
 */
interface LeadSettings
{
    public function mode(): string;

    /**
     * @return list<string>
     */
    public function models(): array;
}
