<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\SecretValueGuard;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use App\Repository\ModelRepository;

/**
 * Can this model answer right now (GET /api/v1/config/models/{id}/check)?
 *
 * An Ollama model needs the server running and the model pulled. A hosted
 * provider needs its credentials, from the admin key store or the
 * environment. A provider the registry does not know (test, custom) is
 * assumed ready.
 */
final readonly class ModelAvailabilityCheck
{
    public function __construct(
        private ModelRepository $modelRepository,
        private ProviderRegistry $providerRegistry,
        private ChatReadinessService $chatReadiness,
    ) {
    }

    /**
     * @return array{available: bool, provider_type: string, model_name: string, service: string, message?: string, install_command?: string, env_var?: string, setup_instructions?: string}|null null when the model does not exist
     */
    public function check(int $modelId): ?array
    {
        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return null;
        }

        $service = strtolower($model->getService());
        $modelName = $model->getProviderId() ?: $model->getName();
        $providerType = 'unknown';
        $available = false;
        $message = null;
        $installCommand = null;
        $envVar = null;

        if ('ollama' === $service) {
            $providerType = 'local';

            try {
                $provider = $this->providerRegistry->getChatProvider('ollama');

                // A reachable Ollama says nothing about THIS model: a stock
                // install has the server up with only the embedding model
                // pulled. Report the concrete model, not the server health.
                if (empty($provider->getStatus()['healthy'])) {
                    $message = 'Ollama server is not running';
                } elseif ($this->chatReadiness->isOllamaModelPulled($modelName)) {
                    $available = true;
                } else {
                    $message = sprintf('Ollama is running but the model "%s" is not downloaded yet', $modelName);
                }

                $installCommand = "docker compose exec ollama ollama pull {$modelName}";
            } catch (\Exception $e) {
                $message = 'Ollama not available: '.$e->getMessage();
            }
        } elseif (null !== ($registeredProvider = $this->findProvider($service))) {
            $providerType = 'external';
            $configuration = $this->requiredConfiguration($registeredProvider, $service);
            $available = $configuration['available'];
            $message = $configuration['message'];
            $envVar = $configuration['env_var'];
        } else {
            $available = true;
        }

        $result = [
            'available' => $available,
            'provider_type' => $providerType,
            'model_name' => $modelName,
            'service' => $service,
        ];

        if ($message) {
            $result['message'] = $message;
        }

        if ($installCommand) {
            $result['install_command'] = $installCommand;
        }

        if ($envVar) {
            $result['env_var'] = $envVar;
            $result['setup_instructions'] = "Configure {$envVar} under Admin → AI Providers, or set it in the environment";
        }

        return $result;
    }

    private function findProvider(string $serviceLower): ?ProviderMetadataInterface
    {
        foreach ($this->providerRegistry->getUniqueProviders() as $provider) {
            if (strtolower($provider->getName()) === $serviceLower) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Whether a registered provider has the credentials it needs.
     *
     * {@see ProviderMetadataInterface::isAvailable()} comes first so a key
     * saved through the admin UI counts without a matching .env entry. The
     * provider's own check may only look at its primary key, while
     * getRequiredEnvVars() documents accepted alternatives (`any_of`) and
     * additional credentials; those are honoured before declaring it
     * unconfigured. The first missing name is returned as a setup hint.
     *
     * @return array{available: bool, message: ?string, env_var: ?string}
     */
    private function requiredConfiguration(ProviderMetadataInterface $provider, string $serviceLabel): array
    {
        if ($provider->isAvailable()) {
            return ['available' => true, 'message' => null, 'env_var' => null];
        }

        $envVarHint = null;
        foreach ($provider->getRequiredEnvVars() as $envName => $meta) {
            if (false === ($meta['required'] ?? true)) {
                continue;
            }

            $candidates = isset($meta['any_of']) && \is_array($meta['any_of'])
                ? array_values(array_filter($meta['any_of'], 'is_string'))
                : [$envName];

            foreach ($candidates as $candidate) {
                if (SecretValueGuard::isUsable($this->envValue($candidate))) {
                    continue 2;
                }
            }

            $envVarHint ??= $candidates[0] ?? $envName;
        }

        if (null === $envVarHint) {
            return ['available' => true, 'message' => null, 'env_var' => null];
        }

        return [
            'available' => false,
            'message' => "Configuration not complete for {$serviceLabel}",
            'env_var' => $envVarHint,
        ];
    }

    private function envValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? $value : null;
    }
}
