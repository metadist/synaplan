<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Credential\ProviderKeyStore;
use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
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
        $encryption = $doubles['encryption'] ?? new EncryptionService('test-secret', new NullLogger());

        return new SystemConfigService(
            projectDir: $doubles['projectDir'] ?? sys_get_temp_dir(),
            logger: $doubles['logger'] ?? new NullLogger(),
            configRepository: $repository,
            defaultTtsUrl: self::TTS_URL,
            providerKeyStore: $doubles['providerKeyStore'] ?? new ProviderKeyStore($repository, $encryption, new NullLogger()),
            encryption: $encryption,
            registrationConfig: new RegistrationConfig($repository),
            guestChatConfig: new GuestChatConfig($repository),
            featureFlagEnv: $doubles['featureFlagEnv'] ?? new FeatureFlagEnv(),
            modules: $doubles['modules'] ?? null,
            layeredConfigResolver: $doubles['layeredConfigResolver'] ?? null,
            computeClient: $doubles['computeClient'] ?? null,
            chatArtifactsConfig: $doubles['chatArtifactsConfig'] ?? null,
        );
    }
}
