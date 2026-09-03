<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Model\ModelCatalog;

/**
 * Resolves the catalog `tool_use` flag for a chat model without hitting the DB.
 *
 * Providers that implement ToolCallingChatProviderInterface use this (or the
 * persisted Model::hasFeature('tool_use') on a loaded row) so the two gates
 * describe the same reality.
 */
final class CatalogToolUse
{
    /**
     * Chat services whose upstream documents function calling. Every chat-tag
     * row in these families MUST carry `tool_use`. Ollama / Triton are
     * opt-in (only when the pulled model is flagged).
     *
     * @var list<string>
     */
    public const CAPABLE_CHAT_SERVICES = [
        'openai',
        'groq',
        'anthropic',
        'google',
        'xai',
        'mistral',
        'huggingface',
        'trustedtokens',
    ];

    /**
     * Tags that must never carry `tool_use`, even when they share a provider
     * id with a chat row.
     *
     * @var list<string>
     */
    public const NON_CHAT_TAGS = [
        'pic2text',
        'text2pic',
        'text2vid',
        'text2sound',
        'sound2text',
        'vectorize',
        'mem',
    ];

    private function __construct()
    {
    }

    public static function supports(string $service, string $providerModelId): bool
    {
        // Dev/test catalog lives in ModelSeeder::TEST_MODELS, not ModelCatalog.
        if ('test' === strtolower($service) && 'test-model' === $providerModelId) {
            return true;
        }

        $row = self::findChatRow($service, $providerModelId);
        if (null === $row) {
            return false;
        }
        $features = $row['json']['features'] ?? [];

        return is_array($features) && in_array('tool_use', $features, true);
    }

    public static function hasChatRow(string $service, string $providerModelId): bool
    {
        return null !== self::findChatRow($service, $providerModelId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findChatRow(string $service, string $providerModelId): ?array
    {
        $serviceKey = ModelCatalog::normalizeProvider($service);
        $providerKey = strtolower($providerModelId);
        foreach (ModelCatalog::all() as $row) {
            if ('chat' !== ($row['tag'] ?? '')) {
                continue;
            }
            if (ModelCatalog::normalizeProvider((string) $row['service']) !== $serviceKey) {
                continue;
            }
            if (strtolower((string) $row['providerId']) !== $providerKey) {
                continue;
            }

            return $row;
        }

        return null;
    }

    public static function isCapableChatService(string $service): bool
    {
        return in_array(ModelCatalog::normalizeProvider($service), self::CAPABLE_CHAT_SERVICES, true);
    }
}
