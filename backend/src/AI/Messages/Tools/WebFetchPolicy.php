<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Service\MessagesGateway\MessagesGatewayConfig;

/**
 * Ensures Anthropic's `web_fetch_*` server tool reaches api.anthropic.com.
 *
 * Unlike {@see WebSearchTool}, Synaplan does not execute page fetches itself —
 * only Anthropic can honour the server tool. This policy therefore:
 *
 * - on Anthropic routes: keep / inject the declaration and make sure the
 *   `anthropic-beta` header carries the web-fetch beta when needed;
 * - on OpenAI / Gemini aliases: drop the declaration (those translators cannot
 *   map it) and report `off` so clients can tell why fetch is unavailable.
 *
 * @phpstan-type PolicyResult array{
 *     body: array<string, mixed>,
 *     anthropic_beta: string|null,
 *     handling: string,
 *     mutated: bool
 * }
 */
final class WebFetchPolicy
{
    public const HANDLING_NONE = 'none';
    public const HANDLING_PASSTHROUGH = 'passthrough';
    public const HANDLING_OFF = 'off';

    /** Stable Anthropic server-tool shape the model understands today. */
    public const DEFAULT_DECLARATION = [
        'type' => 'web_fetch_20250910',
        'name' => 'web_fetch',
        'max_uses' => 5,
    ];

    /** Beta feature id required by older Anthropic web_fetch type versions. */
    public const BETA_FEATURE = 'web-fetch-2025-09-10';

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return PolicyResult
     */
    public function apply(
        array $requestBody,
        string $provider,
        string $mode,
        ?string $anthropicBeta,
    ): array {
        $isAnthropic = 'anthropic' === strtolower($provider);
        $declared = $this->hasServerWebFetch($requestBody);
        $clientOwns = $this->hasClientToolNamed($requestBody, AnthropicServerTools::WEB_FETCH_NAME);

        if (MessagesGatewayConfig::WEB_FETCH_OFF === $mode) {
            if (!$declared) {
                return $this->result($requestBody, $anthropicBeta, self::HANDLING_NONE, false);
            }

            return $this->result(
                $this->stripServerWebFetch($requestBody),
                $anthropicBeta,
                self::HANDLING_OFF,
                true,
            );
        }

        // auto / passthrough — never Synaplan-executed.
        if (!$isAnthropic) {
            if (!$declared) {
                return $this->result($requestBody, $anthropicBeta, self::HANDLING_NONE, false);
            }

            return $this->result(
                $this->stripServerWebFetch($requestBody),
                $anthropicBeta,
                self::HANDLING_OFF,
                true,
            );
        }

        if ($clientOwns) {
            // Client ships an executable tool named web_fetch — leave it alone.
            return $this->result($requestBody, $anthropicBeta, self::HANDLING_NONE, false);
        }

        $mutated = false;
        $body = $requestBody;
        if (!$declared) {
            $body = $this->injectDefaultDeclaration($body);
            $mutated = true;
        }

        $beta = $this->ensureBeta($anthropicBeta);

        return $this->result($body, $beta, self::HANDLING_PASSTHROUGH, $mutated);
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    public function hasServerWebFetch(array $requestBody): bool
    {
        if (!isset($requestBody['tools']) || !\is_array($requestBody['tools'])) {
            return false;
        }

        foreach ($requestBody['tools'] as $tool) {
            if (\is_array($tool) && AnthropicServerTools::isWebFetch($tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public function stripServerWebFetch(array $requestBody): array
    {
        if (!isset($requestBody['tools']) || !\is_array($requestBody['tools'])) {
            return $requestBody;
        }

        $kept = [];
        foreach ($requestBody['tools'] as $tool) {
            if (\is_array($tool) && AnthropicServerTools::isWebFetch($tool)) {
                continue;
            }
            $kept[] = $tool;
        }
        $requestBody['tools'] = $kept;

        return $requestBody;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    private function injectDefaultDeclaration(array $requestBody): array
    {
        $tools = isset($requestBody['tools']) && \is_array($requestBody['tools'])
            ? $requestBody['tools']
            : [];
        array_unshift($tools, self::DEFAULT_DECLARATION);
        $requestBody['tools'] = $tools;

        return $requestBody;
    }

    private function ensureBeta(?string $anthropicBeta): string
    {
        $current = null !== $anthropicBeta ? trim($anthropicBeta) : '';
        if ('' === $current) {
            return self::BETA_FEATURE;
        }

        $parts = array_map('trim', explode(',', $current));
        foreach ($parts as $part) {
            if (self::BETA_FEATURE === $part) {
                return $current;
            }
        }

        return $current.','.self::BETA_FEATURE;
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function hasClientToolNamed(array $requestBody, string $name): bool
    {
        if (!isset($requestBody['tools']) || !\is_array($requestBody['tools'])) {
            return false;
        }

        foreach ($requestBody['tools'] as $tool) {
            if (!\is_array($tool)) {
                continue;
            }
            if ($name === ($tool['name'] ?? null) && !AnthropicServerTools::isServerToolDeclaration($tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return PolicyResult
     */
    private function result(array $body, ?string $beta, string $handling, bool $mutated): array
    {
        return [
            'body' => $body,
            'anthropic_beta' => $beta,
            'handling' => $handling,
            'mutated' => $mutated,
        ];
    }
}
