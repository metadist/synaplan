<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderBranding;
use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderDefaults;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderDialects;
use App\AI\Catalog\ProviderEndpoint;
use App\AI\Catalog\ProviderKeyPresentation;
use App\AI\Catalog\ProviderKeySpec;
use App\AI\Catalog\ProviderKeyValidation;
use App\AI\Catalog\ProviderPricing;
use App\AI\Catalog\ReasoningLadder;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputDialect;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Scaleway Generative APIs — OpenAI-compatible models hosted in Paris.
 *
 * Chat, tools and images use `/v1/chat/completions`. Embeddings use
 * `/v1/embeddings`. Whisper uses `/v1/audio/transcriptions`. The secret
 * key is sent as `Authorization: Bearer` (`SCALEWAY_API_KEY`, the same
 * value as Scaleway's `SCW_SECRET_KEY`).
 *
 * gpt-oss-120b advertises tools, but Scaleway's Chat Completions path
 * does not run them. That row is chat only.
 *
 * @see https://www.scaleway.com/en/developers/api/generative-apis
 */
#[AsAiProvider]
class ScalewayProvider extends AbstractChatCompletionsCloudProvider implements EmbeddingProviderInterface, SpeechToTextProviderInterface
{
    private const PROVIDER_NAME = 'scaleway';
    private const BASE_URI = 'https://api.scaleway.ai/v1';
    private const DEFAULT_CHAT_MODEL = 'qwen3.8-27b';
    private const DEFAULT_VISION_MODEL = 'qwen3.8-27b';
    private const DEFAULT_EMBEDDING_MODEL = 'qwen3-embedding-8b';
    private const DEFAULT_STT_MODEL = 'whisper-large-v3';
    private const AUDIO_MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Longer prefixes first: `deepseek-v4` also matches `deepseek-v4.1`.
     *
     * @var array<string, list<string>>
     */
    private const REASONING_EFFORTS = [
        'qwen3.8' => ['none', 'low', 'medium', 'xhigh'],
        'qwen3.6' => ['none', 'low', 'medium', 'high'],
        'qwen3.5' => ['none', 'low', 'medium', 'high'],
        'gemma-4' => ['none', 'low', 'medium', 'high'],
        'deepseek-v4.1' => ['none', 'low', 'high', 'max'],
        'deepseek-v4' => ['none', 'low', 'high', 'max'],
        'glm-5' => ['none', 'low', 'high', 'max'],
        'mistral-medium' => ['none', 'high'],
        'gpt-oss' => ['low', 'medium', 'high'],
    ];

    public function __construct(
        LoggerInterface $logger,
        ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
        ?ProviderKeyStore $keyStore = null,
        StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
        private readonly ?HttpClientInterface $http = null,
    ) {
        parent::__construct($logger, $apiKey, $this->uploadDir, $keyStore, $structuredOutputTranslator, $http);
    }

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'Scaleway',
            capabilities: [
                ProviderCapability::Chat,
                ProviderCapability::Vision,
                ProviderCapability::Embedding,
                ProviderCapability::SpeechToText,
            ],
            endpoints: [
                ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions'),
                ProviderEndpoint::api(self::BASE_URI.'/embeddings'),
                ProviderEndpoint::api(self::BASE_URI.'/audio/transcriptions'),
            ],
            key: ProviderKeySpec::platform(
                envVar: 'SCALEWAY_API_KEY',
                consoleUrl: 'https://console.scaleway.com/iam/api-keys',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 16,
                modelListing: ModelListing::Lists,
                fieldDescription: 'Scaleway secret key for Generative APIs (Paris). Create it under IAM → API keys. The same value Scaleway calls SCW_SECRET_KEY.',
                configSection: ProviderKeyPresentation::SECTION_CLOUD,
                configOrder: 12,
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                // Not every chat row can call tools (GPT OSS 120B and the PDF
                // OCR model cannot). Tool use is the row's `tool_use` feature.
                catalogToolUse: false,
                reasoning: ReasoningLadder::prefixes(self::REASONING_EFFORTS),
            ),
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'scaleway:qwen3.8-27b:chat',
                'TOOLS' => 'scaleway:qwen3.8-27b:chat',
                'ANALYZE' => 'scaleway:qwen3.8-27b:chat',
                'PIC2TEXT' => 'scaleway:qwen3.8-27b:pic2text',
                'SORT' => 'scaleway:mistral-small-3.2-24b-instruct-2506:chat',
                'PLAN' => 'scaleway:mistral-small-3.2-24b-instruct-2506:chat',
                'SUMMARIZE' => 'scaleway:mistral-small-3.2-24b-instruct-2506:chat',
                'MEM' => 'scaleway:gpt-oss-120b:mem',
                'SOUND2TEXT' => 'scaleway:whisper-large-v3:sound2text',
            ], preferenceRank: 13),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'simple-icons:scaleway', flag: 'circle-flags:fr', helpKey: self::PROVIDER_NAME),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Scaleway';
    }

    public function getDescription(): string
    {
        return 'Scaleway Generative APIs — OpenAI-compatible chat, vision, embeddings and transcription hosted in Paris (api.scaleway.ai).';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'embedding', 'speech_to_text'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
            'embedding' => self::DEFAULT_EMBEDDING_MODEL,
            'speech_to_text' => self::DEFAULT_STT_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    protected function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $request = parent::buildChatOptions($messages, $options, $stream);
        $effort = $this->resolveReasoningEffort((string) ($options['model'] ?? ''), $options);
        if (null !== $effort) {
            $request['reasoning_effort'] = $effort;
        }

        return $request;
    }

    protected function envVarName(): string
    {
        return 'SCALEWAY_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Create a secret key at https://console.scaleway.com/iam/api-keys, then paste it under Admin → AI infrastructure → Providers & keys.';
    }

    public function embed(string $text, array $options = []): array
    {
        $batch = $this->embedBatch([$text], $options);

        return [
            'embedding' => $batch['embeddings'][0] ?? [],
            'usage' => $batch['usage'],
        ];
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $model = $options['model'] ?? null;
        if (!is_string($model) || '' === $model) {
            throw new ProviderException('Embedding model must be specified in options', self::PROVIDER_NAME);
        }
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key || null === $this->http) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'SCALEWAY_API_KEY');
        }

        $payload = ['model' => $model, 'input' => 1 === count($texts) ? $texts[0] : array_values($texts)];
        $response = $this->http->request('POST', self::BASE_URI.'/embeddings', [
            'headers' => ['Authorization' => 'Bearer '.$key],
            'json' => $payload,
        ]);
        $body = $response->toArray(false);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new ProviderException('Scaleway embedding error: '.($body['error']['message'] ?? $statusCode), self::PROVIDER_NAME, null, $statusCode);
        }

        return [
            'embeddings' => array_map(static fn (array $item): array => $item['embedding'] ?? [], $body['data'] ?? []),
            'usage' => [
                'prompt_tokens' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'total_tokens' => (int) ($body['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    public function getDimensions(string $model): int
    {
        return match (true) {
            str_contains($model, 'qwen3-embedding-8b') => 4096,
            str_contains($model, 'bge-multilingual-gemma2') => 3584,
            default => 4096,
        };
    }

    public function transcribe(string $audioPath, array $options = []): array
    {
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key || null === $this->http) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'SCALEWAY_API_KEY');
        }
        $fullPath = str_starts_with($audioPath, '/') ? $audioPath : $this->uploadDir.'/'.ltrim($audioPath, '/');
        if (!is_file($fullPath)) {
            throw new ProviderException('Audio file not found: '.basename($audioPath), self::PROVIDER_NAME);
        }
        $size = filesize($fullPath);
        if (false !== $size && $size > self::AUDIO_MAX_BYTES) {
            throw new ProviderException('Audio file is over 25 MB.', self::PROVIDER_NAME);
        }

        $fields = [
            'model' => is_string($options['model'] ?? null) && '' !== $options['model'] ? $options['model'] : self::DEFAULT_STT_MODEL,
            'file' => DataPart::fromPath($fullPath),
            'response_format' => 'verbose_json',
        ];
        if (!empty($options['language']) && is_string($options['language'])) {
            $fields['language'] = $options['language'];
        }
        $form = new FormDataPart($fields);
        $response = $this->http->request('POST', self::BASE_URI.'/audio/transcriptions', [
            'headers' => ['Authorization' => 'Bearer '.$key] + $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToIterable(),
        ]);
        $body = $response->toArray(false);
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new ProviderException('Scaleway transcription error: '.($body['error']['message'] ?? $statusCode), self::PROVIDER_NAME, null, $statusCode);
        }

        return [
            'text' => (string) ($body['text'] ?? ''),
            'language' => (string) ($body['language'] ?? 'unknown'),
            'duration' => (float) ($body['duration'] ?? 0),
            'segments' => is_array($body['segments'] ?? null) ? $body['segments'] : [],
        ];
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('Scaleway Whisper transcribes speech. It does not translate it.', self::PROVIDER_NAME);
    }

    /**
     * An explicit level wins when the model accepts it. The Thinking toggle
     * maps off to the cheapest accepted level and on to the catalog default,
     * or to `high` when that level exists. No signal leaves Scaleway's default.
     *
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(string $model, array $options): ?string
    {
        $allowed = $this->allowedReasoningEfforts($model);
        if (null === $allowed) {
            return null;
        }

        $explicit = $options['reasoning_effort'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), $allowed, true)) {
            return strtolower($explicit);
        }

        if (!array_key_exists('reasoning', $options)) {
            return null;
        }

        $features = $options['modelFeatures'] ?? null;
        if (is_array($features) && !in_array('reasoning', $features, true)) {
            return null;
        }

        if (!$this->isReasoningEnabled($options['reasoning'])) {
            return $allowed[0];
        }

        $config = $options['modelConfig'] ?? null;
        $default = is_array($config) ? ($config['reasoning_effort_default'] ?? null) : null;
        if (is_string($default) && in_array($default, $allowed, true)) {
            return $default;
        }

        if (in_array('high', $allowed, true)) {
            return 'high';
        }

        return $allowed[array_key_last($allowed)];
    }

    /**
     * @return list<string>|null
     */
    private function allowedReasoningEfforts(string $model): ?array
    {
        foreach (self::REASONING_EFFORTS as $prefix => $levels) {
            if (str_starts_with(strtolower($model), $prefix)) {
                return $levels;
            }
        }

        return null;
    }

    private function isReasoningEnabled(mixed $reasoning): bool
    {
        if (is_array($reasoning)) {
            return [] !== $reasoning;
        }

        return (bool) $reasoning;
    }
}
