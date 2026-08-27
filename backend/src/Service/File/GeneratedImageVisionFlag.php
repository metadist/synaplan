<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\ConfigRepository;

/**
 * Rollout switch for showing assistant-GENERATED images to a vision chat model.
 *
 * Without it, "create an image of a cat" → "what breed is it?" is answered from
 * the text prompt alone, because only user turns contribute image content.
 *
 * Reads BCONFIG group `FILE_CONTEXT`, key `VISION_INCLUDE_GENERATED`. A per-user
 * row takes precedence over the global row. The default is **off**: every
 * included image adds a base64 payload to each subsequent request of the
 * conversation, which is a real token cost the operator should opt into.
 *
 * To enable globally:
 *   INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
 *     VALUES (0, 'FILE_CONTEXT', 'VISION_INCLUDE_GENERATED', '1');
 */
final readonly class GeneratedImageVisionFlag
{
    public const CONFIG_GROUP = 'FILE_CONTEXT';
    public const KEY_VISION_INCLUDE_GENERATED = 'VISION_INCLUDE_GENERATED';

    /** Newest-first cap on generated images added to a single request. */
    public const MAX_GENERATED_IMAGES = 2;

    public function __construct(private ConfigRepository $configRepository)
    {
    }

    public function isEnabled(?int $userId = null): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_VISION_INCLUDE_GENERATED);
            if (null !== $perUser) {
                return $this->parse($perUser);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_VISION_INCLUDE_GENERATED);
        if (null === $global) {
            return false;
        }

        return $this->parse($global);
    }

    private function parse(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}
