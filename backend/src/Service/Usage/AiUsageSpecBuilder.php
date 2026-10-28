<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\AI\Pipeline\AiAction;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AiCapability;
use App\Repository\ModelRepository;
use App\Service\RateLimitService;

/**
 * Builds `[action, metadata]` specifications and read-only cost/token previews
 * for {@see AiPipelineMeter} (`03_contracts.md` §1.5, S1 AU11).
 */
final readonly class AiUsageSpecBuilder
{
    private const CAPABILITY_TAGS = [
        'chat' => 'chat',
        'vision' => 'pic2text',
        'embedding' => 'vectorize',
        'image_generation' => 'text2pic',
        'video_generation' => 'text2vid',
        'speech_to_text' => 'sound2text',
        'text_to_speech' => 'text2sound',
    ];

    public function __construct(
        private CostCalculationService $costCalculationService,
        private RateLimitService $rateLimitService,
        private ModelRepository $models,
    ) {
    }

    /**
     * Build the `[action, metadata]` pair for a completed `AiCall` when the
     * pipeline hook has everything needed to meter it.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function build(AiCall $call, AiCallResult $result): ?array
    {
        if (AiCapability::Passthrough === $call->capability || isset($call->options->providerOptions['_messages_gateway'])) {
            return $this->buildMessagesGatewayUsageSpec($call, $result->value);
        }

        if (\in_array($call->capability, [AiCapability::Chat, AiCapability::ChatStream], true)) {
            return $this->buildChatUsageSpec($call, $result->value);
        }

        if (\in_array($call->capability, [AiCapability::Embed, AiCapability::EmbedBatch], true)) {
            return $this->buildEmbeddingUsageSpec($call, $result->value);
        }

        return $this->buildMediaUsageSpec($call, $result);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildMessagesGatewayUsageSpec(AiCall $call, mixed $value): ?array
    {
        $opts = $call->options->providerOptions;
        $gw = \is_array($opts['_messages_gateway'] ?? null) ? $opts['_messages_gateway'] : [];
        $keySource = \is_string($gw['key_source'] ?? null) ? $gw['key_source'] : 'operator';
        $inputText = \is_string($gw['input_text'] ?? null) ? $gw['input_text'] : '';

        if ($value instanceof \App\AI\Messages\MessagesUsage) {
            if (!$value->shouldMeter()) {
                return null;
            }
            $usage = $value->toRateLimitUsage();
            $responseText = '';
        } elseif (\is_array($value)) {
            $status = (int) ($value['status'] ?? 200);
            $body = $value['body'] ?? null;
            $msgUsage = $value['usage'] ?? null;
            if ($status >= 400 || !\is_array($body) || !$msgUsage instanceof \App\AI\Messages\MessagesUsage) {
                return null;
            }
            $usage = $msgUsage->toRateLimitUsage();
            $responseText = \App\AI\Messages\MessagesResponseText::extract($body);
        } else {
            return null;
        }

        $metadata = [
            'provider' => $call->provider,
            'model' => (string) $call->model,
            'model_id' => $call->options->modelId,
            'usage' => $usage,
            'response_text' => $responseText,
            'input_text' => $inputText,
            'source' => 'MESSAGES_API',
            'key_source' => $keySource,
        ];
        if ('user' === $keySource) {
            $metadata['zero_cost'] = true;
        }

        return [AiAction::Messages->value, $metadata];
    }

    /**
     * Read-only preview of what {@see RateLimitService::recordUsage()} would
     * compute for `$metadata`, without writing to `BUSELOG`.
     *
     * @param array<string, mixed> $metadata
     */
    public function preview(array $metadata): RecordedUsage
    {
        $usage = \is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['cached_tokens'] ?? 0);
        $cacheCreationTokens = (int) ($usage['cache_creation_tokens'] ?? 0);
        $cacheCreation1hTokens = max(0, (int) ($usage['cache_creation_1h_tokens'] ?? 0));
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));

        $inputBytes = !empty($metadata['input_bytes']) ? (int) $metadata['input_bytes'] : 0;
        if (0 === $inputBytes && !empty($metadata['input_text']) && \is_string($metadata['input_text'])) {
            $inputBytes = \strlen($metadata['input_text']);
        }
        $outputBytes = (!empty($metadata['response_text']) && \is_string($metadata['response_text']) ? \strlen($metadata['response_text']) : 0)
            + (!empty($metadata['response_bytes']) ? (int) $metadata['response_bytes'] : 0);

        if (0 === $totalTokens && ($inputBytes + $outputBytes) > 0) {
            $totalTokens = RateLimitService::estimateTokens($inputBytes + $outputBytes);
        }
        if ($totalTokens > 0 && 0 === $promptTokens && 0 === $completionTokens) {
            if ($inputBytes > 0 && $outputBytes > 0) {
                $ratio = $inputBytes / ($inputBytes + $outputBytes);
                $promptTokens = (int) ceil($totalTokens * $ratio);
                $completionTokens = $totalTokens - $promptTokens;
            } else {
                $promptTokens = $totalTokens;
            }
        }

        $modelId = isset($metadata['model_id']) && is_numeric($metadata['model_id']) ? (int) $metadata['model_id'] : null;
        $mediaUsage = \is_array($metadata['media_usage'] ?? null) ? $metadata['media_usage'] : [];
        $pricingMode = $this->costCalculationService->getPricingMode($modelId);
        $timestamp = time();

        if (!empty($metadata['zero_cost'])) {
            $rawCost = 0.0;
            $priceKnown = true;
        } elseif ('per_token' !== $pricingMode && [] !== $mediaUsage) {
            $inputQty = match ($pricingMode) {
                'per_character' => (float) ($mediaUsage['characters'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                'per_request' => (float) ($mediaUsage['requests'] ?? 1),
                default => 0.0,
            };
            $outputQty = match ($pricingMode) {
                'per_image' => (float) ($mediaUsage['images'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                'per_generation' => (float) ($mediaUsage['clips'] ?? 1),
                default => 0.0,
            };
            $costResult = $this->costCalculationService->calculateMediaCost(
                $modelId,
                $inputQty,
                $outputQty,
                $timestamp,
                \is_string($mediaUsage['resolution'] ?? null) ? $mediaUsage['resolution'] : null,
                \is_string($mediaUsage['quality'] ?? null) ? $mediaUsage['quality'] : null,
                \is_string($mediaUsage['size'] ?? null) ? $mediaUsage['size'] : null,
            );
            $rawCost = (float) $costResult->totalCost;
            $priceKnown = false !== ($costResult->priceSnapshot['price_known'] ?? true);
        } else {
            $costResult = $this->costCalculationService->calculateCost(
                $promptTokens,
                $completionTokens,
                $cachedTokens,
                $cacheCreationTokens,
                $modelId,
                $timestamp,
                $cacheCreation1hTokens,
            );
            $rawCost = (float) $costResult->totalCost;
            $priceKnown = false !== ($costResult->priceSnapshot['price_known'] ?? true);
        }

        $chargedCost = $rawCost * (1.0 + ($this->rateLimitService->getMarkupPercent() / 100.0));

        return new RecordedUsage(
            chargedCost: number_format($chargedCost, 6, '.', ''),
            rawCost: number_format($rawCost, 6, '.', ''),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            priceKnown: $priceKnown,
        );
    }

    public function resolveModelId(string $capability, string $providerName, ?string $providerModelId): ?int
    {
        if (null === $providerModelId || '' === trim($providerModelId)) {
            return null;
        }

        if ('chat' === $capability) {
            return $this->models->findChatIdByProvider($providerName, $providerModelId);
        }

        $tag = self::CAPABILITY_TAGS[$capability] ?? null;
        if (null === $tag) {
            return null;
        }

        try {
            return $this->models->findIdByServiceProviderIdAndTag($providerName, $providerModelId, $tag);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildChatUsageSpec(AiCall $call, mixed $value): ?array
    {
        $opts = $call->options->providerOptions;
        $billTo = \is_array($opts['_bill_to'] ?? null) ? $opts['_bill_to'] : [];
        $explicitAction = \is_string($billTo['action'] ?? null) && '' !== $billTo['action']
            ? $billTo['action']
            : null;

        if ($call->options->callerMeters && null === $explicitAction) {
            return null;
        }

        $action = $explicitAction ?? AiChatUsageMeter::ACTION;
        $provider = $call->provider;
        $model = (string) ($call->model ?? 'unknown');
        $usage = \is_array($value) && \is_array($value['usage'] ?? null) ? $value['usage'] : [];
        $modelId = $call->options->modelId
            ?? (isset($billTo['model_id']) && is_numeric($billTo['model_id']) ? (int) $billTo['model_id'] : null)
            ?? $this->resolveModelId('chat', $provider, $model);

        $metadata = [
            'provider' => $provider,
            'model' => $model,
            'model_id' => $modelId,
            'usage' => $usage,
            'input_text' => self::messagesText($call->payload->messages),
            'response_text' => \is_array($value) && \is_string($value['content'] ?? null) ? $value['content'] : '',
        ];
        if (AiChatUsageMeter::ACTION === $action) {
            $source = $billTo['source'] ?? null;
            $metadata['source'] = \is_string($source) && '' !== trim($source) ? trim($source) : 'ai_facade';
        }
        foreach (['chat_id', 'agentId', 'agentVersionId'] as $key) {
            if (isset($billTo[$key])) {
                $metadata[$key] = $billTo[$key];
            }
        }

        return [$action, $metadata];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildEmbeddingUsageSpec(AiCall $call, mixed $value): ?array
    {
        $opts = $call->options->providerOptions;
        $usageMeta = \is_array($opts['_usage_meta'] ?? null) ? $opts['_usage_meta'] : [];
        $action = \is_string($usageMeta['usage_action'] ?? null) ? $usageMeta['usage_action'] : null;
        if (null === $action) {
            return null;
        }
        $modelId = isset($usageMeta['model_id']) && is_numeric($usageMeta['model_id'])
            ? (int) $usageMeta['model_id']
            : ($call->options->modelId ?? $this->resolveModelId('embedding', $call->provider, $call->model));
        $usage = \is_array($value) && \is_array($value['usage'] ?? null)
            ? $value['usage']
            : ['prompt_tokens' => 0, 'total_tokens' => 0];

        $metadata = [
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'provider' => $call->provider,
            'model' => (string) $call->model,
            'model_id' => $modelId,
            'input_bytes' => array_sum(array_map('strlen', $call->payload->texts)),
            'source' => \is_string($usageMeta['usage_source'] ?? null) ? $usageMeta['usage_source'] : 'VECTORIZATION',
        ];
        if (isset($usageMeta['usage_inputs'])) {
            $metadata['inputs'] = (int) $usageMeta['usage_inputs'];
        }

        return [$action, $metadata];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildMediaUsageSpec(AiCall $call, AiCallResult $result): ?array
    {
        $opts = $call->options->providerOptions;
        $value = $result->value;

        if (AiCapability::Image === $call->capability) {
            if (!\is_array($value) || [] === $value) {
                return null;
            }
            $usageMeta = \is_array($opts['_usage_meta'] ?? null) ? $opts['_usage_meta'] : [];
            $source = \is_string($usageMeta['usage_source'] ?? null) ? $usageMeta['usage_source'] : null;
            if (null === $source && (!\is_array($value[0] ?? null) || !isset($value[0]['url']))) {
                return null;
            }
            $modelId = isset($usageMeta['model_id']) && is_numeric($usageMeta['model_id'])
                ? (int) $usageMeta['model_id']
                : ($call->options->modelId ?? $this->resolveModelId('image_generation', $call->provider, $call->model));

            $metadata = [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => array_filter([
                    'images' => (float) \count($value),
                    'quality' => $opts['quality'] ?? 'standard',
                    'size' => $opts['size'] ?? '1024x1024',
                ]),
            ];
            if (null !== $source) {
                $metadata['source'] = $source;
            }

            return [AiAction::Images->value, $metadata];
        }

        if (AiCapability::Synthesize === $call->capability) {
            $modelId = $call->options->modelId ?? $this->resolveModelId('text_to_speech', $call->provider, $call->model);
            $chars = null !== $result->usage ? $result->usage->characterCount : mb_strlen((string) $call->payload->prompt);

            return [AiAction::Audios->value, [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => ['characters' => $chars],
            ]];
        }

        if (AiCapability::Transcribe === $call->capability) {
            if (!\is_array($value)) {
                return null;
            }
            $modelId = $call->options->modelId ?? $this->resolveModelId('speech_to_text', $call->provider, $call->model);
            $duration = (float) ($value['duration'] ?? 0) + (float) ($value['first_pass_duration'] ?? 0);
            if (null === $modelId || $duration <= 0.0) {
                return null;
            }

            return [AiAction::Transcription->value, [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => ['duration_seconds' => $duration],
                'language' => $value['language'] ?? 'unknown',
            ]];
        }

        return null;
    }

    /**
     * @param list<mixed> $messages
     */
    private static function messagesText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (\is_string($content) && '' !== $content) {
                $parts[] = $content;
            }
        }

        return implode("\n", $parts);
    }
}
