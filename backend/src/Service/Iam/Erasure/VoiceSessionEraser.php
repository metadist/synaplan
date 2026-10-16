<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\VoiceSession;
use App\Repository\VoiceSessionRepository;
use App\Service\Voice\VoiceLiveConcurrency;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's live voice sessions; their usage rows go with
 * {@see UsageLogEraser}. A session still open is closed best effort: after
 * the commit its provider slot is released, and the voice gateway hangs up
 * on its next usage report, which now finds no session. The provider's own
 * timeout is the backstop.
 */
#[AsTaggedItem(priority: 125)]
final readonly class VoiceSessionEraser implements UserDataEraserInterface
{
    public function __construct(
        private VoiceSessionRepository $sessions,
        private VoiceLiveConcurrency $concurrency,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $open = $this->sessions->findOpenForUser($erasure->userId);
        if (null !== $open) {
            $provider = $open->getProvider();
            $erasure->afterCommit(fn () => $this->concurrency->release($provider));
        }
        $this->sessions->deleteByUser($erasure->userId);
    }

    public static function covers(): array
    {
        return [VoiceSession::class];
    }
}
