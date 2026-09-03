<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Exception\ChatFailureReason;

/**
 * User-safe presentation of a failed chat / sorting / generation call.
 *
 * `$userText` is always localized and free of provider internals.
 * `$adminDetail` is the raw technical dump and is only filled when the
 * caller asked for diagnostics (admins).
 */
final readonly class ChatErrorView
{
    public function __construct(
        public ChatFailureReason $reason,
        public string $userText,
        public ?string $adminDetail,
        public bool $canRetryWithOtherModel,
        public string $rawMessage,
    ) {
    }

    /**
     * @return array{errorReason: string, canRetryModel: bool, errorDebug: ?string}
     */
    public function toSseFields(?string $adminDetailOverride = null): array
    {
        return [
            'errorReason' => $this->reason->value,
            'canRetryModel' => $this->canRetryWithOtherModel,
            'errorDebug' => $adminDetailOverride,
        ];
    }
}
