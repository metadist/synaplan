<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Catalog\Attribute\AsAiProvider;
use App\AI\Catalog\InlineImageRule;
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
use App\AI\Catalog\ReasoningLadder;
use App\AI\Exception\ProviderException;
use App\AI\Image\PngJpegInlineImages;
use App\AI\Image\UnsupportedImageInputException;
use App\AI\StructuredOutput\StructuredOutputDialect;

/**
 * Cerebras Inference — wafer-scale hosting for open models (GPT OSS 120B,
 * Qwen 3.8 27B) at roughly 2,000–3,000 tokens per second.
 *
 * OpenAI-compatible Chat Completions at https://api.cerebras.ai/v1 with
 * `Authorization: Bearer`. Differences that matter here:
 *
 *   - Reasoning is controlled by the standard `reasoning_effort` field.
 *     qwen-3.8-27b accepts none|low|medium|high and defaults to high;
 *     gpt-oss-120b accepts low|medium|high and cannot turn reasoning off.
 *   - Reasoning streams in `delta.reasoning`, not `delta.reasoning_content`.
 *   - gpt-oss-120b rejects `tools` together with `response_format`; Cerebras
 *     documents the combination as model-dependent, so it is never sent.
 *   - Images must be base64 PNG or JPEG data URIs without `detail`; other
 *     formats are transcoded and links are rejected ({@see PngJpegInlineImages}).
 *
 * @see https://inference-docs.cerebras.ai/resources/openai
 * @see https://inference-docs.cerebras.ai/capabilities/reasoning
 */
#[AsAiProvider]
class CerebrasProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'cerebras';
    private const BASE_URI = 'https://api.cerebras.ai/v1';
    private const DEFAULT_CHAT_MODEL = 'qwen-3.8-27b';
    private const DEFAULT_VISION_MODEL = 'qwen-3.8-27b';

    /**
     * Model family prefix => accepted `reasoning_effort` values, cheapest first.
     * Keep aligned with {@see ReasoningLevelCatalog::cerebrasLevels()}.
     *
     * @var array<string, list<string>>
     */
    private const REASONING_EFFORTS = [
        'qwen-3.8' => ['none', 'low', 'medium', 'high'],
        'gpt-oss' => ['low', 'medium', 'high'],
    ];

    public static function descriptor(): ProviderDescriptor
    {
        return new ProviderDescriptor(
            name: self::PROVIDER_NAME,
            displayName: 'Cerebras',
            capabilities: [ProviderCapability::Chat, ProviderCapability::Vision],
            endpoints: [ProviderEndpoint::chatCompletions(self::BASE_URI.'/chat/completions')],
            key: ProviderKeySpec::platform(
                envVar: 'CEREBRAS_API_KEY',
                consoleUrl: 'https://cloud.cerebras.ai/',
                validation: ProviderKeyValidation::bearer(self::BASE_URI.'/models'),
                listOrder: 10,
                modelListing: ModelListing::Lists,
                freeTier: true,
                fieldDescription: 'Cerebras Inference API key — GPT OSS 120B and Qwen 3.8 27B. Create it at https://cloud.cerebras.ai/ (API Keys)',
            ),
            dialects: new ProviderDialects(
                structuredOutput: StructuredOutputDialect::OPENAI_JSON_SCHEMA,
                // gpt-oss-120b rejects tools next to response_format; Cerebras
                // documents the combination as model-dependent.
                toolsWithStructuredOutput: false,
                catalogToolUse: true,
                inlineImages: InlineImageRule::PngJpegOnly,
                reasoning: ReasoningLadder::prefixes(self::REASONING_EFFORTS),
            ),
            // Qwen 3.8 27B for the main tier (tools, vision, reasoning that the
            // Thinking toggle can switch off). gpt-oss-120b for the fast tier: a
            // third of the price, ~3000 t/s, and the same strict-schema family the
            // Groq SORT/PLAN default already runs.
            defaults: new ProviderDefaults(bindings: [
                'CHAT' => 'cerebras:qwen-3.8-27b:chat',
                'TOOLS' => 'cerebras:qwen-3.8-27b:chat',
                'ANALYZE' => 'cerebras:qwen-3.8-27b:chat',
                'SORT' => 'cerebras:gpt-oss-120b:chat',
                'PLAN' => 'cerebras:gpt-oss-120b:chat',
                'SUMMARIZE' => 'cerebras:gpt-oss-120b:chat',
                'MEM' => 'cerebras:gpt-oss-120b:mem',
                'PIC2TEXT' => 'cerebras:qwen-3.8-27b:pic2text',
            ], preferenceRank: 11),
            pricing: ProviderPricing::byServiceName(),
            liveVoiceSessions: null,
            branding: new ProviderBranding(icon: 'logos:cerebras-icon', flag: 'circle-flags:us', helpKey: 'cerebras'),
        );
    }

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Cerebras';
    }

    public function getDescription(): string
    {
        return 'Cerebras Inference — very fast open models (GPT OSS 120B, Qwen 3.8 27B). Prompts are processed by Cerebras (US). OpenAI-compatible API.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return 'CEREBRAS_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Create an API key at https://cloud.cerebras.ai/ (API Keys).';
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

        try {
            $request['messages'] = PngJpegInlineImages::normalizeMessages($request['messages'], $this->getDisplayName());
        } catch (UnsupportedImageInputException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), null, 0, $e);
        }

        $effort = $this->resolveReasoningEffort((string) $options['model'], $options);
        if (null !== $effort) {
            $request['reasoning_effort'] = $effort;
        }

        // The schema wins: something downstream parses against it, while
        // "no tool call" is a valid outcome of every toolset we declare.
        if (isset($request['response_format'], $request['tools'])) {
            unset($request['tools'], $request['tool_choice'], $request['parallel_tool_calls']);
        }

        return $request;
    }

    /**
     * Describing an image needs no thinking, and on Qwen the default `high`
     * effort spends most of the vision output cap before the answer starts.
     */
    protected function visionRequestOptions(string $model): array
    {
        $levels = $this->allowedReasoningEfforts($model);

        return null !== $levels && in_array('none', $levels, true)
            ? ['reasoning_effort' => 'none']
            : [];
    }

    protected function prepareImageUrl(string $imageUrl): string
    {
        try {
            return PngJpegInlineImages::toDataUrl($imageUrl, $this->getDisplayName());
        } catch (UnsupportedImageInputException $e) {
            throw new ProviderException($e->getMessage(), $this->getName(), null, 0, $e);
        }
    }

    protected function streamedReasoning(array $responseArray): ?string
    {
        $reasoning = $responseArray['choices'][0]['delta']['reasoning'] ?? null;

        return is_string($reasoning) && '' !== $reasoning ? $reasoning : null;
    }

    /**
     * Resolution order mirrors {@see MetaProvider}: an explicit level wins, then
     * the Thinking toggle (off → cheapest level the model accepts, on → catalog
     * default or `high`). No signal sends nothing, so Cerebras' default applies.
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

        return 'high';
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
