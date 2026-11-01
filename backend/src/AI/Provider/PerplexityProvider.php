<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\DescribesProviderInterface;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\Interface\ChatProviderInterface;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Perplexity chat — OpenAI-compatible `/chat/completions` at api.perplexity.ai.
 * Search stays on {@see \App\Plug\WebSearch\Adapter\PerplexityAdapter}; both
 * share {@see ProviderKeyStore} key `perplexity`.
 */
#[AsAiProvider]
final class PerplexityProvider implements ChatProviderInterface, DescribesProviderInterface
{
    private const PROVIDER_NAME = 'perplexity';
    private const DISPLAY_NAME = 'Perplexity';
    private const ENV_VAR = 'PERPLEXITY_API_KEY';
    private const BASE_URI = 'https://api.perplexity.ai';
    private const DEFAULT_CHAT_MODEL = 'sonar';

    private ?OpenAI\Client $client = null;
    private ?string $clientKey = null;

    /**
     * @param (\Closure(string): OpenAI\Client)|null $sdkFactory
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly ?ProviderKeyStore $keyStore = null,
        private readonly ?\Closure $sdkFactory = null,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: self::DISPLAY_NAME,
            capabilities: [ProviderCapability::Chat],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: self::ENV_VAR,
                consoleUrl: 'https://www.perplexity.ai/account/api',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 12,
                modelListing: ModelListing::Lists,
            ),
            // Sonar answers from live search; no schema mode, no tool routing.
            dialects: new ProviderDialects(),
            // A chat default, never auto-picked on first run: Sonar is a web answer
            // engine, not a general assistant.
            defaults: new ProviderDefaults(bindings: ['CHAT' => 'perplexity:sonar:chat'], preferenceRank: null),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'mdi:robot', helpKey: 'perplexity'),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getDescription(): string
    {
        return 'Perplexity Sonar models with live web-grounded chat';
    }

    public function getCapabilities(): array
    {
        return ['chat'];
    }

    public function getDefaultModels(): array
    {
        return ['chat' => self::DEFAULT_CHAT_MODEL];
    }

    public function getStatus(): array
    {
        if (!$this->hasKey()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'error' => null,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->hasKey();
    }

    private function hasKey(): bool
    {
        $key = $this->resolveApiKey();

        return null !== $key && '' !== $key;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            self::ENV_VAR => [
                'required' => true,
                'hint' => 'Get your API key from https://www.perplexity.ai/account/api',
            ],
        ];
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', self::PROVIDER_NAME);
        }
        if (null === $this->client()) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, self::ENV_VAR);
        }

        try {
            $request = [
                'model' => (string) $options['model'],
                'messages' => $messages,
                'max_tokens' => (int) ($options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS),
            ];
            if (isset($options['temperature'])) {
                $request['temperature'] = (float) $options['temperature'];
            }

            $response = $this->client()->chat()->create($request);
            $arr = $response->toArray();
            $usageRaw = is_array($arr['usage'] ?? null) ? $arr['usage'] : [];

            return [
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => [
                    'prompt_tokens' => (int) ($usageRaw['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usageRaw['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($usageRaw['total_tokens'] ?? 0),
                    'cached_tokens' => 0,
                    'cache_creation_tokens' => 0,
                ],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Perplexity chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw (new ProviderFailureFactory())->fromThrowable($e, self::PROVIDER_NAME, 'chat');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $result = $this->chat($messages, $options);
        $content = (string) $result['content'];
        if ('' !== $content) {
            $callback($content);
        }
        $callback(['type' => 'finish', 'finish_reason' => 'stop']);

        return [
            'usage' => $result['usage'],
        ];
    }

    private function resolveApiKey(): ?string
    {
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            return $this->apiKey;
        }

        return $this->keyStore?->getKey($this->getName());
    }

    private function requireHttp(): HttpClientInterface
    {
        if (null === $this->httpClient) {
            throw new \LogicException('Perplexity requires the shared HTTP client.');
        }

        return $this->httpClient;
    }

    private function client(): ?OpenAI\Client
    {
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key) {
            $this->client = null;
            $this->clientKey = null;

            return null;
        }

        if (null === $this->client || $this->clientKey !== $key) {
            $this->client = null !== $this->sdkFactory
                ? ($this->sdkFactory)($key)
                : \OpenAI::factory()
                    ->withApiKey($key)
                    ->withBaseUri(self::BASE_URI)
                    ->withHttpClient(new \Symfony\Component\HttpClient\Psr18Client($this->requireHttp()))
                    ->make();
            $this->clientKey = $key;
        }

        return $this->client;
    }
}
