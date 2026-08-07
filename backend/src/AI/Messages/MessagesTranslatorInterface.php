<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Protocol adapter that speaks the Anthropic Messages API on the client side
 * and a concrete upstream provider on the other.
 *
 * {@see stream()} always emits already-Anthropic-shaped SSE event payloads via
 * $emit (raw JSON object for each `event:` / `data:` pair, or raw bytes on the
 * Anthropic passthrough fast path — see translator docs). Controllers never
 * branch per provider above this seam.
 */
interface MessagesTranslatorInterface
{
    public function supports(string $providerName): bool;

    /**
     * Non-streaming completion.
     *
     * @param array<string, mixed> $requestBody Anthropic-shaped request body
     * @param array{
     *     api_key: string,
     *     upstream_url: string,
     *     anthropic_version?: string|null,
     *     anthropic_beta?: string|null,
     *     x_fixture?: string|null,
     *     raw_body?: string|null,
     *     parsed_events?: bool
     * } $context
     *
     * @return array{status: int, headers: array<string, list<string>>, body: array<string, mixed>|string, usage: MessagesUsage}
     */
    public function complete(array $requestBody, array $context): array;

    /**
     * Streaming completion. $emit is invoked with either:
     *   - string $chunk  — raw SSE bytes (Anthropic passthrough fast path), or
     *   - array{event: string, data: array<string, mixed>} — synthesized events
     *
     * @param array<string, mixed> $requestBody
     * @param array{
     *     api_key: string,
     *     upstream_url: string,
     *     anthropic_version?: string|null,
     *     anthropic_beta?: string|null,
     *     x_fixture?: string|null,
     *     raw_body?: string|null,
     *     parsed_events?: bool
     * } $context
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     */
    public function stream(array $requestBody, array $context, callable $emit): MessagesUsage;
}
