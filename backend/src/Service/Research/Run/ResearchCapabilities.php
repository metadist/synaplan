<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Plug\WebSearch\WebSearchHosting;

/**
 * What this installation can do for a research run.
 *
 * Reading whole documents stays off. A daily cap is not counted yet, so
 * those two limits are null rather than a number that would block a start.
 */
final readonly class ResearchCapabilities
{
    public const PROTOCOL = 1;

    public function __construct(
        private PlugConfigService $plugs,
        private ResearchConfig $config,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $user): array
    {
        $provider = strtolower($this->plugs->webSearchProvider($user->getId()));
        $known = array_key_exists($provider, WebSearchHosting::PROVIDERS);

        return [
            'enabled' => true,
            'research_protocol' => self::PROTOCOL,
            'depths' => ['quick', 'standard', 'thorough'],
            'web_search_available' => $known,
            'web_search_provider' => $known ? [
                'id' => $provider,
                'self_hosted' => WebSearchHosting::selfHosted($provider),
                'country' => WebSearchHosting::country($provider),
            ] : null,
            'knowledge_available' => false,
            'knowledge_index_model' => null,
            'read_documents' => false,
            'strict_world' => false,
            'max_active_thorough' => $this->config->maxActiveThorough(),
            'limits' => [
                'standard_per_day' => null,
                'thorough_per_day' => null,
            ],
        ];
    }
}
