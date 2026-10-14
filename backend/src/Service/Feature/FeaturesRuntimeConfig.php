<?php

declare(strict_types=1);

namespace App\Service\Feature;

use App\Service\Agent\AgentConfig;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Iam\IamConfig;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\SavedTask\SavedTaskConfig;

/**
 * Account features: saved tasks, desktop, assistants, partner links and groups.
 */
final readonly class FeaturesRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private IamConfig $iamConfig,
        private SavedTaskConfig $savedTaskConfig,
        private DesktopAgentConfig $desktopAgentConfig,
        private AgentConfig $agentConfig,
        private PlatformLinksConfig $platformLinksConfig,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $userId = $context->user?->getId();

        return [
            'features' => [
                'savedTasks' => $this->savedTaskConfig->isEnabled($userId),
                'desktopAgentEnabled' => $this->desktopAgentConfig->isEnabled($userId),
                'platformLinksEnabled' => $this->platformLinksConfig->isEnabled($userId),
                'agentsEnabled' => $this->agentConfig->isEnabled($userId),
                'iamGroups' => $this->iamConfig->isGroupsEnabled($userId),
                'iamSharing' => $this->iamConfig->isSharingEnabled($userId),
                'iamImpersonationDisabled' => $this->iamConfig->isImpersonationDisabled($userId),
                'iamPolicies' => $this->iamConfig->isGroupPoliciesEnabled($userId),
            ],
        ];
    }
}
