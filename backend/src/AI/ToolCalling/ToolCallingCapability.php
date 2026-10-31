<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

use App\AI\Catalog\ProviderCatalog;

/**
 * Answers "may the native tool-calling ROUTING path use this provider +
 * model + streaming-mode?" — the single place the hard, provider-specific
 * rules are applied, mirroring {@see \App\AI\StructuredOutput\StructuredOutputCapability}.
 *
 * This sits ON TOP of the transport-level gate. Whether a provider can carry
 * tools at all is answered by
 * {@see \App\AI\Interface\ToolCallingChatProviderInterface::supportsToolCalling()}
 * plus the catalog `tool_use` flag ({@see \App\AI\Tool\CatalogToolUse}), and
 * that is true for every chat provider. The descriptors' `nativeToolRouting`
 * is narrower on purpose: it names the providers whose STREAMING path also
 * hands the accumulated calls back in `tool_calls`, which the routing hand-off
 * needs and the transport contract does not require. A provider without the
 * flag silently keeps the AI-sorter round-trip
 * ({@see \App\Service\Message\MessageClassifier}); a provider wrongly flagged
 * would break the hottest path in the product.
 *
 * `toolsWithStructuredOutput: false` names the providers for which a tool
 * declaration and a JSON-schema response format cannot travel in the same
 * request (Groq 400s, Cerebras per model, Anthropic because the schema IS a
 * forced tool call). The providers enforce it themselves: tools reach them
 * from several places and only the provider sees the moment both end up in
 * one payload.
 */
final class ToolCallingCapability
{
    private readonly ProviderCatalog $catalog;

    public function __construct(?ProviderCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? ProviderCatalog::builtIn();
    }

    /**
     * `$model` and `$streaming` are part of the question by design, mirroring
     * {@see \App\AI\StructuredOutput\StructuredOutputCapability::supports()}
     * where both decide. Here neither narrows the answer yet: the rules are
     * per provider, and streaming support is an entry condition of the flag
     * rather than a variant of it.
     */
    public function supports(string $providerName, ?string $model, bool $streaming): bool
    {
        return true === $this->catalog->find($providerName)?->dialects->nativeToolRouting;
    }

    public function conflictsWithStructuredOutput(string $providerName): bool
    {
        return false === $this->catalog->find($providerName)?->dialects->toolsWithStructuredOutput;
    }
}
