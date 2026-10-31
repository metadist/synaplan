<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\ComputeSettingGuard;
use App\Service\Admin\SystemConfig\EnvSettings;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Service\Admin\SystemConfig\SystemConfigValues;
use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Admin\SystemConfig\Tab\AiTab;
use App\Service\Admin\SystemConfig\Tab\AuthTab;
use App\Service\Admin\SystemConfig\Tab\BrandingTab;
use App\Service\Admin\SystemConfig\Tab\ChannelsTab;
use App\Service\Admin\SystemConfig\Tab\EmailTab;
use App\Service\Admin\SystemConfig\Tab\FeaturesTab;
use App\Service\Admin\SystemConfig\Tab\GuestLandingTab;
use App\Service\Admin\SystemConfig\Tab\InterfaceTab;
use App\Service\Admin\SystemConfig\Tab\MobileTab;
use App\Service\Admin\SystemConfig\Tab\OutboundTab;
use App\Service\Admin\SystemConfig\Tab\ProcessingTab;
use App\Service\Admin\SystemConfig\Tab\RoutingTab;
use App\Service\Admin\SystemConfig\Tab\SharingTab;
use App\Service\Admin\SystemConfig\Tab\VectorDbTab;
use App\Service\Admin\SystemConfigService;
use App\Service\Auth\RegistrationConfig;
use App\Service\Chat\ChatArtifactsConfig;
use App\Service\Chat\GuestChatConfig;
use App\Service\Compute\ComputeClient;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Security\EncryptionService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The system settings service, built from the doubles a test names. The
 * access flags read the same repository; everything else not named is the
 * production default or absent.
 */
final class SystemConfigServiceFactory
{
    public const TTS_URL = 'http://localhost:10200';

    /**
     * @param array{
     *     configRepository: ConfigRepository,
     *     projectDir?: string,
     *     logger?: LoggerInterface,
     *     encryption?: EncryptionService,
     *     providerKeyStore?: ProviderKeyStore,
     *     featureFlagEnv?: FeatureFlagEnv,
     *     modules?: ?ModuleRegistry,
     *     layeredConfigResolver?: ?LayeredConfigResolver,
     *     computeClient?: ?ComputeClient,
     *     chatArtifactsConfig?: ?ChatArtifactsConfig,
     * } $doubles
     */
    public static function build(array $doubles): SystemConfigService
    {
        $repository = $doubles['configRepository'];
        $logger = $doubles['logger'] ?? new NullLogger();
        $encryption = $doubles['encryption'] ?? new EncryptionService('test-secret', new NullLogger());
        $schema = self::schema($repository, $doubles['modules'] ?? null);
        $env = new EnvSettings($doubles['projectDir'] ?? sys_get_temp_dir(), $logger);

        return new SystemConfigService(
            schema: $schema,
            values: new SystemConfigValues(
                $schema,
                $repository,
                $doubles['providerKeyStore'] ?? new ProviderKeyStore($repository, $encryption, new NullLogger(), new ProviderKeyCatalog(ProviderCatalog::builtIn())),
                $env,
                new RegistrationConfig($repository),
                new GuestChatConfig($repository),
                new ProviderKeyCatalog(ProviderCatalog::builtIn()),
                $doubles['featureFlagEnv'] ?? new FeatureFlagEnv(),
                $doubles['chatArtifactsConfig'] ?? null,
            ),
            writer: new SystemConfigWriter(
                $schema,
                $repository,
                $encryption,
                $env,
                new ComputeSettingGuard($repository, $doubles['computeClient'] ?? null),
                $logger,
                $doubles['layeredConfigResolver'] ?? null,
            ),
            env: $env,
            http: new \Symfony\Component\HttpClient\MockHttpClient(),
            logger: $logger,
            defaultTtsUrl: self::TTS_URL,
        );
    }

    /**
     * The tabs in the order the container collects them.
     */
    public static function schema(ConfigRepository $repository, ?ModuleRegistry $modules = null): SystemConfigSchema
    {
        return new SystemConfigSchema([
            new AiTab(self::TTS_URL),
            new EmailTab(),
            new BrandingTab(),
            new MobileTab(),
            new AuthTab(),
            new ChannelsTab(),
            new ProcessingTab(),
            new FeaturesTab($modules),
            new OutboundTab(),
            new SharingTab(new RegistrationConfig($repository)),
            new RoutingTab(),
            new InterfaceTab(),
            new GuestLandingTab(),
            new VectorDbTab(),
        ], new ProviderKeyCatalog(ProviderCatalog::builtIn()));
    }
}
