<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver — the kill switch for the whole structured-output
 * rollout.
 *
 * Flag lives in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ENABLED — master switch. When ON, every call-site that classifies or
 *     extracts JSON (`MessageSorter`, `TaskPlanner`, `MemoryExtractionService`,
 *     `MessageDigestService`, the three `FeedbackContradictionService`/
 *     `FeedbackExampleService` JSON calls, `WidgetSetupService`,
 *     `UserMemoryController`, and the `officemaker` topic in `ChatHandler`)
 *     attaches a {@see StructuredOutputSchema}. When OFF, none of them do —
 *     every provider falls back to the prompt-instruction + tolerant-parser
 *     behaviour that predates this rollout, with zero code-path difference
 *     (the schema was always the ONLY thing `$options` gained; nothing
 *     downstream requires it).
 *
 * Resolution mirrors {@see \App\Service\Desktop\DesktopAgentConfig}: a
 * per-user row (BOWNERID = userId) overrides the global row (BOWNERID = 0),
 * which overrides the built-in code default (ON).
 *
 * Default ON — unlike most new-feature flags this is a quality/reliability
 * hardening of an EXISTING code path (JSON parsing that already ran on every
 * sort/plan/extraction call), not a new surface area users opt into. A
 * provider without schema support ({@see StructuredOutputCapability}) is
 * already a no-op regardless of this flag, so OFF is reserved for the rare
 * case of a provider-side schema bug that needs a same-second rollback
 * without a deploy.
 */
final readonly class StructuredOutputConfig
{
    public const CONFIG_GROUP = 'STRUCTURED_OUTPUT';
    public const KEY_ENABLED = 'ENABLED';

    private const DEFAULT_ENABLED = true;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Per-user override wins, then global, then built-in
     * default (ON). Pass the effective user id (null for anonymous /
     * unresolved — e.g. the officemaker synthetic message built by
     * DocumentGenerationRunner already carries the real owning user's id).
     */
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
