<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\ResearchConfig;

/** The RESEARCH.LEAD_MODE and RESEARCH.TOOL_MODE_MODELS rows. */
final readonly class ConfigLeadSettings implements LeadSettings
{
    public function __construct(private ResearchConfig $config)
    {
    }

    public function mode(): string
    {
        return $this->config->leadMode();
    }

    public function models(): array
    {
        return $this->config->toolModeModels();
    }
}
