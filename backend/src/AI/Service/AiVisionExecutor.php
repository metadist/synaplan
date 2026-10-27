<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * Vision provider candidate selection, fallback chain and pipeline execution
 * behind {@see AiFacade::analyzeImage()}.
 */
final readonly class AiVisionExecutor
{
    public function __construct(
        private ProviderRegistry $registry,
        private ModelConfigService $modelConfig,
        private AiCallPipeline $pipeline,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function analyzeImage(string $imagePath, string $prompt, ?int $userId = null, array $options = []): array
    {
        [$candidates, $modelOwnerProvider] = $this->resolveCandidates($imagePath, $userId, $options);
        $attempted = [];
        $lastException = null;

        foreach ($candidates as $candidate) {
            $candidateName = $candidate['name'];
            $normalizedCandidate = strtolower($candidateName);

            if (\in_array($normalizedCandidate, $attempted, true)) {
                continue;
            }
            $attempted[] = $normalizedCandidate;

            try {
                $provider = $this->registry->getVisionProvider($candidateName, $candidate['requireCapability']);
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider not available', [
                    'provider' => $candidateName,
                    'require_capability' => $candidate['requireCapability'],
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                continue;
            }

            $candidateOptions = $options;
            $candidateOptions['provider'] = $candidateName;
            if (null !== $modelOwnerProvider && $modelOwnerProvider !== $normalizedCandidate) {
                unset($candidateOptions['model']);
            }

            try {
                return $this->attemptCandidate($provider, $imagePath, $prompt, $userId, $candidateOptions);
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider failed: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
            } catch (\Throwable $e) {
                $this->logger->error('AI vision provider error: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = new ProviderException(
                    'Vision provider error: '.$e->getMessage(),
                    $provider->getName(),
                    null,
                    0,
                    $e,
                );
            }
        }

        $this->logger->error('AI vision failed after exhausting providers', [
            'image' => basename($imagePath),
            'attempted' => $attempted,
        ]);

        throw $lastException ?? new ProviderException('Vision failed', 'unknown');
    }

    /**
     * @param array<string, mixed> $candidateOptions
     *
     * @return array<string, mixed>
     */
    private function attemptCandidate(
        VisionProviderInterface $provider,
        string $imagePath,
        string $prompt,
        ?int $userId,
        array $candidateOptions,
    ): array {
        $this->logger->info('AI vision request via '.$provider->getName(), [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'image' => basename($imagePath),
            'model' => $candidateOptions['model'] ?? null,
        ]);

        $reportedModel = self::reportedModel($provider->getDefaultModels(), $candidateOptions, 'vision');
        $call = new AiCall(
            capability: AiCapability::Vision,
            provider: $provider->getName(),
            model: $reportedModel,
            context: new AiCallContext(userId: $userId, source: AiCallSource::ChatWeb),
            payload: AiCallPayload::vision($imagePath, $prompt),
            options: AiCallOptions::fromArray($candidateOptions),
        );
        $response = $this->pipeline->run(
            $call,
            static fn (AiCall $c): mixed => $provider->explainImage((string) $c->payload->filePath, (string) $c->payload->prompt, $c->options->providerOptions),
        )->value;

        return [
            'content' => $response,
            'provider' => $provider->getName(),
            'model' => $reportedModel,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: list<array{name: string, requireCapability: bool}>, 1: ?string}
     */
    private function resolveCandidates(string $imagePath, ?int $userId, array &$options): array
    {
        $providerWasExplicit = \array_key_exists('provider', $options);
        $callerSuppliedModel = \array_key_exists('model', $options);
        $resolvedVisionProvider = null;

        if (!$providerWasExplicit && null !== $userId) {
            $visionDefault = $this->modelConfig->resolveVisionDefault($userId);
            $resolvedVisionProvider = $visionDefault['provider'];

            if (null !== $visionDefault['model_id']) {
                $options['provider'] = $visionDefault['provider'];
                $providerWasExplicit = true;
                if (null !== $visionDefault['model'] && !$callerSuppliedModel) {
                    $options['model'] = $visionDefault['model'];
                }
            }
        }

        $requestedProvider = $options['provider'] ?? $resolvedVisionProvider ?? $this->modelConfig->getDefaultProvider($userId, 'vision');
        $normalizedRequested = $requestedProvider ? strtolower((string) $requestedProvider) : null;
        $modelOwnerProvider = (isset($options['model']) && $normalizedRequested)
            ? $normalizedRequested
            : null;

        return [
            $this->buildCandidates($providerWasExplicit, \is_string($requestedProvider) ? $requestedProvider : null, $normalizedRequested, $userId, $imagePath, $options),
            $modelOwnerProvider,
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{name: string, requireCapability: bool}>
     */
    private function buildCandidates(
        bool $providerWasExplicit,
        ?string $requestedProvider,
        ?string $normalizedRequested,
        ?int $userId,
        string $imagePath,
        array $options,
    ): array {
        $candidates = [];

        if (($providerWasExplicit || null !== $requestedProvider) && $requestedProvider && 'test' !== $normalizedRequested) {
            $candidates[] = [
                'name' => $requestedProvider,
                'requireCapability' => true,
            ];
        }

        $this->logger->info('AI vision request - provider selection', [
            'requested_provider' => $requestedProvider,
            'user_id' => $userId,
            'image' => basename($imagePath),
            'options' => $options,
        ]);

        $fallbackRequireCapability = true;
        $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: true);

        if (empty($fallbackProviders)) {
            $this->logger->info('AI vision fallback: no DB-enabled providers, probing available providers with API keys', [
                'requested_provider' => $requestedProvider,
                'user_id' => $userId,
            ]);

            $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: false);
            $fallbackRequireCapability = false;
        }

        foreach ($fallbackProviders as $fallbackName) {
            if ($normalizedRequested && null !== $requestedProvider && 0 === strcasecmp($fallbackName, $requestedProvider)) {
                continue;
            }

            $candidates[] = [
                'name' => $fallbackName,
                'requireCapability' => $fallbackRequireCapability,
            ];
        }

        if (empty($candidates)) {
            $this->logger->warning('AI vision: No real providers available, falling back to TestProvider (development only)', [
                'user_id' => $userId,
            ]);
            $candidates[] = [
                'name' => 'test',
                'requireCapability' => false,
            ];
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $options
     */
    private static function reportedModel(array $defaults, array $options, string $capability): string
    {
        $requested = $options['model'] ?? null;
        if (\is_string($requested) && '' !== trim($requested)) {
            return $requested;
        }

        $default = $defaults[$capability] ?? null;
        if (\is_string($default) && '' !== trim($default)) {
            return $default;
        }

        return 'unknown';
    }
}
