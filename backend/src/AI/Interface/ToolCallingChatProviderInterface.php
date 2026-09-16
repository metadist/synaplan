<?php

declare(strict_types=1);

namespace App\AI\Interface;

/**
 * Marker for chat providers that can send tools and return tool_calls.
 *
 * Capability is a dual gate: this interface AND the catalog `tool_use` flag
 * on the resolved model. {@see supportsToolCalling()} must return true iff
 * that model is flagged `tool_use` — providers do not keep a private
 * allow-list that can drift from BJSON.features.
 */
interface ToolCallingChatProviderInterface extends ChatProviderInterface
{
    /**
     * Whether this provider will honour tools / tool_choice for $model.
     *
     * $model is the upstream provider model id (BPROVID), not the display name.
     */
    public function supportsToolCalling(string $model): bool;
}
