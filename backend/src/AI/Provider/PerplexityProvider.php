<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Exception\ProviderFailureFactory;
use App\AI\Interface\ChatProviderInterface;
use OpenAI;
use Psr\Log\LoggerInterface;

/**
 * Perplexity chat — OpenAI-compatible `/chat/completions` at api.perplexity.ai.
 * Search stays on {@see \App\Plug\WebSearch\Adapter\PerplexityAdapter}; both
 * share {@see ProviderKeyStore} key `perplexity`.
 */
final class PerplexityProvider implements ChatProviderInterface
{
    private const PROVIDER_NAME = 'perplexity';
    private const DISPLAY_NAME = 'Perplexity';
    private const ENV_VAR = 'PERPLEXITY_API_KEY';
    private const BASE_URI = 'https://api.perplexity.ai';
    private const DEFAULT_CHAT_MODEL = 'sonar';

    private ?OpenAI\Client $client = null;
    private ?string $clientKey = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly ?ProviderKeyStore $keyStore = null,
    ) {
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
        if (null === $this->client()) {
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
        return null !== $this->client();
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

    private function client(): ?OpenAI\Client
    {
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key) {
            $this->client = null;
            $this->clientKey = null;

            return null;
        }

        if (null === $this->client || $this->clientKey !== $key) {
            $this->client = \OpenAI::factory()
                ->withApiKey($key)
                ->withBaseUri(self::BASE_URI)
                ->make();
            $this->clientKey = $key;
        }

        return $this->client;
    }
}
