<?php

declare(strict_types=1);

namespace App\Service\MessagesGateway;

/**
 * Validates one Coding-clients settings write. Clients address a setting by
 * its BCONFIG name in lower case.
 */
final class MessagesGatewayFlagUpdates
{
    /**
     * @param array<mixed> $decoded
     *
     * @return array<string, bool|int|string> keyed by BCONFIG setting name
     *
     * @throws \InvalidArgumentException when a submitted value is out of range
     */
    public static function collect(array $decoded, bool $webSearchAvailable, bool $visionAvailable): array
    {
        $booleans = [
            MessagesGatewayConfig::KEY_ENABLED,
            MessagesGatewayConfig::KEY_ALLOW_OPERATOR_KEY,
            MessagesGatewayConfig::KEY_MCP_TOOLS_ENABLED,
            MessagesGatewayConfig::KEY_MCP_TOOLS_WITH_CLIENT_TOOLS,
            MessagesGatewayConfig::KEY_CONTEXT_INJECTION_ENABLED,
            MessagesGatewayConfig::KEY_BUDGET_NOTICE_ENABLED,
            MessagesGatewayConfig::KEY_SESSION_SUMMARY_ENABLED,
        ];

        $enums = [
            MessagesGatewayConfig::KEY_WEB_SEARCH_MODE => MessagesGatewayConfig::WEB_SEARCH_MODES,
            MessagesGatewayConfig::KEY_WEB_FETCH_MODE => MessagesGatewayConfig::WEB_FETCH_MODES,
            MessagesGatewayConfig::KEY_KNOWLEDGE_TOOLS => MessagesGatewayConfig::KNOWLEDGE_TOOLS_MODES,
            MessagesGatewayConfig::KEY_VISION_MODE => MessagesGatewayConfig::VISION_MODES,
            MessagesGatewayConfig::KEY_VISION_IMAGE_DETAIL => MessagesGatewayConfig::IMAGE_DETAILS,
        ];

        $integers = [
            MessagesGatewayConfig::KEY_MCP_MAX_ITERATIONS => [
                MessagesGatewayConfig::MIN_MCP_MAX_ITERATIONS,
                MessagesGatewayConfig::MAX_MCP_MAX_ITERATIONS,
            ],
            MessagesGatewayConfig::KEY_VISION_MAX_IMAGES => [
                MessagesGatewayConfig::MIN_VISION_MAX_IMAGES,
                MessagesGatewayConfig::MAX_VISION_MAX_IMAGES,
            ],
            MessagesGatewayConfig::KEY_WEB_SEARCH_MAX_ROUNDS => [
                MessagesGatewayConfig::MIN_WEB_SEARCH_MAX_ROUNDS,
                MessagesGatewayConfig::MAX_WEB_SEARCH_MAX_ROUNDS,
            ],
            MessagesGatewayConfig::KEY_WEB_FETCH_MAX_PER_TURN => [
                MessagesGatewayConfig::MIN_WEB_FETCH_MAX_PER_TURN,
                MessagesGatewayConfig::MAX_WEB_FETCH_MAX_PER_TURN,
            ],
        ];

        $updated = [];

        foreach ($booleans as $key) {
            $value = self::submittedValue($decoded, $key);
            if (null === $value) {
                continue;
            }
            $bool = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null === $bool) {
                throw new \InvalidArgumentException(strtolower($key).' must be a boolean.');
            }
            $updated[$key] = $bool;
        }

        foreach ($enums as $key => $allowed) {
            $value = self::submittedValue($decoded, $key);
            if (null === $value) {
                continue;
            }
            $normalized = \is_string($value) ? strtolower(trim($value)) : '';
            if (!\in_array($normalized, $allowed, true)) {
                throw new \InvalidArgumentException(strtolower($key).' must be one of: '.implode(', ', $allowed));
            }
            self::assertModeIsUsable($key, $normalized, $webSearchAvailable, $visionAvailable);
            $updated[$key] = $normalized;
        }

        foreach ($integers as $key => [$min, $max]) {
            $value = self::submittedValue($decoded, $key);
            if (null === $value) {
                continue;
            }
            $int = filter_var($value, \FILTER_VALIDATE_INT);
            if (false === $int || $int < $min || $int > $max) {
                throw new \InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', strtolower($key), $min, $max));
            }
            $updated[$key] = $int;
        }

        return $updated;
    }

    /**
     * Reject a `synaplan` mode the install cannot honour — silently accepting it
     * would leave the admin page showing a capability that never runs.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertModeIsUsable(string $key, string $mode, bool $webSearchAvailable, bool $visionAvailable): void
    {
        if (
            MessagesGatewayConfig::KEY_WEB_SEARCH_MODE === $key
            && MessagesGatewayConfig::WEB_SEARCH_SYNAPLAN === $mode
            && !$webSearchAvailable
        ) {
            throw new \InvalidArgumentException('web_search_mode=synaplan requires a configured web search provider on this instance');
        }

        if (
            MessagesGatewayConfig::KEY_VISION_MODE === $key
            && MessagesGatewayConfig::VISION_SYNAPLAN === $mode
            && !$visionAvailable
        ) {
            throw new \InvalidArgumentException('vision_mode=synaplan requires a configured Synaplan vision (PIC2TEXT) model');
        }
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function submittedValue(array $decoded, string $key): mixed
    {
        return $decoded[strtolower($key)] ?? $decoded[$key] ?? null;
    }
}
