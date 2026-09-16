<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Entity\ApiKey;
use App\Security\ApiKeyScope;
use App\Service\Agent\Policy\AssistantSkillGate;
use App\Service\Runtime\RuntimeProfile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Whether this request may be offered `code_execution`.
 *
 * Sidecar + {@see ComputeConfig::isEnabled()} must both be on, the API key
 * must grant {@see ApiKeyScope::COMPUTE_RUN} (or `*`), and an assistant pin
 * must opt in to `code_run`.
 */
final readonly class ComputeRunGrant
{
    public function __construct(
        private ComputeConfig $computeConfig,
        private RequestStack $requestStack,
    ) {
    }

    public function allows(?int $userId = null, ?RuntimeProfile $assistant = null): bool
    {
        if (!$this->computeConfig->isEnabled($userId)) {
            return false;
        }

        if (!ApiKeyScope::grantsComputeRun($this->currentScopes())) {
            return false;
        }

        return AssistantSkillGate::allows($assistant, 'code_run');
    }

    /**
     * @return list<string>
     */
    public function currentScopes(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $apiKey = $request?->attributes->get('api_key');
        if (!$apiKey instanceof ApiKey) {
            return [];
        }

        return $apiKey->getScopes();
    }
}
