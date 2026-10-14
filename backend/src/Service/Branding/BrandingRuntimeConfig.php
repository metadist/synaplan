<?php

declare(strict_types=1);

namespace App\Service\Branding;

use App\AI\Service\AiProviderDisclosure;
use App\Service\Client\ClientContextResolver;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\MarketingNews\MarketingNewsConfig;

/**
 * White-label branding, the named AI providers and the client identity.
 */
final readonly class BrandingRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private BrandingService $brandingService,
        private MarketingNewsConfig $marketingNewsConfig,
        private AiProviderDisclosure $aiProviderDisclosure,
        private ClientContextResolver $clientContextResolver,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $request = $context->request;
        $client = $this->clientContextResolver->fromRequest($request);

        return [
            'branding' => $this->brandingService->getBranding() + [
                'resolvedLogoUrl' => $this->brandingService->getLogoUrl(false)
                    ?? $request->getBasePath().'/synaplan-dark.svg',
                'resolvedLogoDarkUrl' => $this->brandingService->getLogoUrl(true)
                    ?? $request->getBasePath().'/synaplan-light.svg',
                'resolvedIconUrl' => $this->brandingService->getIconUrl(false)
                    ?? $request->getBasePath().'/single_bird-dark.svg',
                'resolvedIconDarkUrl' => $this->brandingService->getIconUrl(true)
                    ?? $request->getBasePath().'/single_bird-light.svg',
            ],
            'client' => [
                'isMobileApp' => $client->isMobileApp,
                'appVersion' => $client->appVersion,
                'platform' => $client->platform(),
            ],
            'aiProviders' => $this->aiProviderDisclosure->chatProviderNames(),
            'marketingNews' => [
                'enabled' => $this->marketingNewsConfig->isEnabled(),
            ],
        ];
    }
}
