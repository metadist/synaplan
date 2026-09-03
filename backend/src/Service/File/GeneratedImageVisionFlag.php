<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\ConfigRepository;

/**
 * Kill-switch for showing assistant-GENERATED images to a vision chat model.
 *
 * "Create an image of a cat" → "what is in it?" needs the pixels, not just a
 * text mention that a file exists (#1596). A per-user row takes precedence over
 * the global row. The default is **on**: without the bytes the model cannot
 * describe its own output. Each included image adds a base64 payload to later
 * requests of the conversation, so the operator can turn it off.
 *
 * Newest-first cap is 1 so a single inline image stays under
 * {@see \App\Service\Message\Handler\ChatHandler}'s 450K-character vision
 * budget (two full-size generated PNGs would overflow Anthropic's 1M-token
 * window even after downscaling).
 *
 * To disable globally:
 *   UPDATE BCONFIG SET BVALUE='0'
 *    WHERE BOWNERID=0 AND BGROUP='FILE_CONTEXT' AND BSETTING='VISION_INCLUDE_GENERATED';
 */
final readonly class GeneratedImageVisionFlag
{
    public const CONFIG_GROUP = 'FILE_CONTEXT';
    public const KEY_VISION_INCLUDE_GENERATED = 'VISION_INCLUDE_GENERATED';

    /** Newest-first cap on generated images added to a single request. */
    public const MAX_GENERATED_IMAGES = 1;

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
            return true;
        }

        return $this->parse($global);
    }

    private function parse(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}
