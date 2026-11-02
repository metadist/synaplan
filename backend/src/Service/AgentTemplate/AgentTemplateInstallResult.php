<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

final readonly class AgentTemplateInstallResult
{
    public function __construct(
        public int $agentId,
        public bool $alreadyInstalled,
        public bool $shared,
        public bool $sharingOff,
    ) {
    }
}
