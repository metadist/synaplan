<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\Repository\ConfigRepository;

/**
 * Feature flag for the native tool-calling routing path.
 *
 * A hard requirement rather than a convenience: this path changes the hottest
 * path in the product, so it ships behind its own flag, gets released on its
 * own, and must be able to fall back to the AI-sorter path at any time. With
 * the flag OFF — the default, including for every existing install — the
 * classifier keeps making the AI-sorter call exactly as before, so all of this
 * is inert until an operator opts in.
 *
 * What it switches on: instead of spending one LLM call on classifying the
 * message and a second on answering it, the answering call itself carries the
 * hand-off tools from {@see RoutingToolset}. The common case — an ordinary
 * chat turn — then costs ONE call, because "no tool was called" already means
 * "just answer".
 *
 * Resolution mirrors {@see EmbeddingRouterConfig} and
 * {@see \App\AI\StructuredOutput\StructuredOutputConfig}: a per-user row
 * (BOWNERID = userId) overrides the global row (BOWNERID = 0), which overrides
 * the built-in default. Per-user resolution is what makes a staged rollout
 * (one internal account first) possible without a deploy.
 */
final readonly class NativeToolRoutingConfig
{
    public const CONFIG_GROUP = 'NATIVE_TOOL_ROUTING';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = false;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(?int $userId): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_ENABLED);
            if (null !== $perUser) {
                return $this->toBool($perUser);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null !== $global) {
            return $this->toBool($global);
        }

        return self::DEFAULT_ENABLED;
    }

    private function toBool(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }
}
