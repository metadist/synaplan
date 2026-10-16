<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Service\Client\ClientContextResolver;
use App\Service\Client\MobileVersionService;
use App\Service\Config\Runtime\RuntimeConfigContext;
use App\Service\Config\Runtime\RuntimeConfigContributorInterface;
use App\Service\Usage\UsageTaximeterConfig;

/**
 * Billing switch, the usage taximeter and the mobile-app update gate.
 */
final readonly class BillingRuntimeConfig implements RuntimeConfigContributorInterface
{
    public function __construct(
        private BillingService $billingService,
        private UsageTaximeterConfig $usageTaximeterConfig,
        private MobileVersionService $mobileVersionService,
        private ClientContextResolver $clientContextResolver,
    ) {
    }

    public function contribute(RuntimeConfigContext $context): array
    {
        $client = $this->clientContextResolver->fromRequest($context->request);
        $storeUrls = $this->mobileVersionService->getStoreUrls();

        return [
            'billing' => [
                'enabled' => $this->billingService->isEnabled(),
            ],
            'usageTaximeter' => [
                'enabled' => $this->usageTaximeterConfig->isEnabled(),
            ],
            'mobile' => [
                'minVersion' => $this->mobileVersionService->getMinVersion(),
                'updateRequired' => $this->mobileVersionService->isUpdateRequired($client),
                'updateEnforceAfter' => $this->mobileVersionService->getUpdateEnforceAfter(),
                'iosAppUrl' => $storeUrls['ios'],
                'androidAppUrl' => $storeUrls['android'],
            ],
        ];
    }
}
