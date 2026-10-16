<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\Document\DocumentToolsConfig;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\SelfAware\SelfAwareConfig;
use App\Service\SmartSearch\Interpret\SearchInterpreter;
use App\Service\Tool\ToolsConfig;

/**
 * Chat chrome and the chat-owned flags inside `features`.
 */
final readonly class ChatRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private ProgressNarrationConfig $progressNarrationConfig,
        private ?ChatActionPolicy $chatActionPolicy = null,
        private ?ChatWelcomeConfig $chatWelcomeConfig = null,
        private ?SelfAwareConfig $selfAwareConfig = null,
        private ?SearchInterpreter $searchInterpreter = null,
        private ?DocumentToolsConfig $documentToolsConfig = null,
        private ?ToolsConfig $toolsConfig = null,
        private ?WorkflowsConfig $workflowsConfig = null,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $user = $context->user;

        return [
            'chatWelcome' => [
                'showStoreCards' => $this->chatWelcomeConfig?->showStoreCards() ?? true,
                'showWidgetPromo' => $this->chatWelcomeConfig?->showWidgetPromo() ?? true,
            ],
            'chatActions' => [
                'export' => $this->chatActionPolicy?->canExport($user?->getId()) ?? true,
                'share' => $this->chatActionPolicy?->canShare($user?->getId()) ?? true,
            ],
            'progressNarration' => $this->progressNarrationConfig->toRuntimeConfig(),
            'features' => [
                'selfAware' => null !== $this->selfAwareConfig && $this->selfAwareConfig->isEnabled($user?->getId()),
                'documentToolsEnabled' => null !== $this->documentToolsConfig && $this->documentToolsConfig->isEnabled($user?->getId()),
                'toolsRegistryEnabled' => null !== $this->toolsConfig && $this->toolsConfig->isRegistryEnabled($user?->getId()),
                'toolsApprovalsEnabled' => null !== $this->toolsConfig && $this->toolsConfig->isApprovalsEnabled($user?->getId()),
                'toolsCustomHttpEnabled' => null !== $this->toolsConfig && $this->toolsConfig->isCustomHttpEnabled($user?->getId()),
                'workflowsBuilderEnabled' => null !== $this->workflowsConfig && $this->workflowsConfig->isBuilderEnabled($user?->getId()),
                'smartSearchAi' => null !== $user && null !== $this->searchInterpreter && $this->searchInterpreter->isAvailable($user),
            ],
        ];
    }
}
