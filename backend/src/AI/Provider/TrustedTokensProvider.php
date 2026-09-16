<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * TrustedTokens — sovereign OpenAI-compatible inference hosted in Germany
 * by TNG Technology Consulting (EU data residency).
 *
 * Chat and vision share the same `/v1/chat/completions` endpoint. Vision is
 * only offered by models that advertise it (Qwen3.6 and GLM-5.3-Flash);
 * GLM-5.2, GLM-5.3, DeepSeek V4, Chimera and GPT OSS 120B are text-only.
 *
 * @see https://trustedtokens.eu/docs/
 * @see https://api.trustedtokens.eu/v1
 */
class TrustedTokensProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'trustedtokens';
    private const BASE_URI = 'https://api.trustedtokens.eu/v1';
    private const DEFAULT_CHAT_MODEL = 'zai-org/GLM-5.2';
    private const DEFAULT_VISION_MODEL = 'Qwen/Qwen3.6-35B-A3B-FP8';

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'TrustedTokens';
    }

    public function getDescription(): string
    {
        return 'Sovereign LLM inference on German infrastructure (TNG Technology Consulting). OpenAI-compatible API.';
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
        return 'TRUSTEDTOKENS_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Get your API key from https://trustedtokens.eu/ (Account → API Access)';
    }
}
