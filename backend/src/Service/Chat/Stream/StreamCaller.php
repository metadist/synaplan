<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Entity\WidgetSession;
use App\Service\Chat\Run\ChatRunService;

/**
 * Who streams a turn, and from where.
 *
 * A signed-in person streams as themselves. A widget visitor streams as the
 * widget owner, a guest on the trial as the shared processing user; both
 * keep their session so limits, counters and the resumable run are scoped
 * to the visitor, not to the shared user.
 */
final readonly class StreamCaller
{
    /**
     * @param string      $uiLanguage       the interface locale, the seed of the inbound message language
     * @param string|null $clientCountry    approximate country from the Cloudflare edge (CF-IPCountry)
     * @param string|null $widgetTaskPrompt the task prompt the widget is locked to
     */
    public function __construct(
        public User $user,
        public string $uiLanguage,
        public ?string $clientCountry = null,
        public ?WidgetSession $widgetSession = null,
        public ?GuestSession $guestSession = null,
        public ?string $widgetTaskPrompt = null,
    ) {
    }

    public function isWidget(): bool
    {
        return null !== $this->widgetSession;
    }

    public function isGuest(): bool
    {
        return null !== $this->guestSession;
    }

    /**
     * The channel recorded with usage: WIDGET, GUEST or WEB.
     */
    public function source(): string
    {
        return match (true) {
            $this->isWidget() => 'WIDGET',
            $this->isGuest() => 'GUEST',
            default => 'WEB',
        };
    }

    /**
     * Owner scope of the resumable run. Widget and guest turns run under a
     * shared user, so the user id alone would let one visitor replay
     * another's conversation — those scopes key on the session instead.
     */
    public function runOwnerKey(): string
    {
        if (null !== $this->widgetSession) {
            return ChatRunService::ownerKeyForWidget($this->widgetSession->getSessionId());
        }

        if (null !== $this->guestSession) {
            return ChatRunService::ownerKeyForGuest($this->guestSession->getSessionId());
        }

        return ChatRunService::ownerKeyForUser((int) $this->user->getId());
    }
}
