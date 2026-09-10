<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Fixture;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Credential\ProviderKeyStore;
use App\Module\Channel\WhatsappModule;
use App\Module\Commerce\MobileIapModule;
use App\Module\Commerce\StripeBillingModule;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\Provider\GoogleAiModule;
use App\Module\Provider\HiggsfieldModule;
use App\Module\Provider\TheHiveModule;
use App\Module\Sidecar\DoclingModule;
use App\Module\Sidecar\LocalAiModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Module\Sidecar\PiperTtsModule;
use App\Module\Sidecar\SearxngModule;
use App\Module\Sidecar\TikaModule;
use App\Service\BillingService;
use App\Service\Iap\AppleReceiptVerifierInterface;
use App\Service\Iap\GooglePlayVerifierInterface;
use App\Service\IapPricingService;

/**
 * Builds every v1 feature-module descriptor with stub collaborators, in the
 * "nothing configured" state. Tests that reason about the module SET (ids,
 * ownership, contract shape) use this instead of booting the kernel.
 *
 * Adding a module to the code base means adding it here too — the contract
 * test compares this list with the plan's module ids.
 */
trait BuildsAllModules
{
    /**
     * @return array<string, FeatureModuleInterface> keyed by module id
     */
    protected function allModules(): array
    {
        $probe = new FakeSidecarHealthProbe();

        $keyStore = $this->createStub(ProviderKeyStore::class);
        $keyStore->method('getStatus')->willReturn(['configured' => false]);

        $higgsfield = $this->createStub(HiggsfieldCredentialResolver::class);
        $higgsfield->method('hasPlatformCredentials')->willReturn(false);

        $modules = [
            new TikaModule($probe, ''),
            new DoclingModule($probe, ''),
            new OfficeConvertModule($probe, ''),
            new SearxngModule($probe, ''),
            new PiperTtsModule($probe, ''),
            new LocalAiModule($probe, ''),
            new HiggsfieldModule($higgsfield),
            new GoogleAiModule($keyStore),
            new TheHiveModule(''),
            new StripeBillingModule(new BillingService('', ''), '', '', ''),
            new MobileIapModule(
                new IapPricingService(),
                $this->createStub(AppleReceiptVerifierInterface::class),
                $this->createStub(GooglePlayVerifierInterface::class),
            ),
            new WhatsappModule(false, '', ''),
        ];

        $byId = [];
        foreach ($modules as $module) {
            $byId[$module->id()] = $module;
        }

        return $byId;
    }
}
