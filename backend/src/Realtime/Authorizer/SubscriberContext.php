<?php

declare(strict_types=1);

namespace App\Realtime\Authorizer;

use App\Entity\User;

/**
 * Identity passed to {@see ChannelAuthorizerInterface}.
 *
 * Either represents an authenticated dashboard user OR an anonymous widget
 * visitor that proved possession of a `(widgetId, sessionId)` pair via the
 * existing widget endpoints. We never trust raw browser-provided identifiers
 * alone — the upstream token controller validates ownership before
 * constructing this object.
 */
final readonly class SubscriberContext
{
    /**
     * @param array<string, scalar|null> $extra context used by individual authorizers
     *                                          (e.g. ['widgetId' => '...', 'sessionId' => '...'])
     */
    public function __construct(
        public ?User $user = null,
        public ?string $visitorId = null,
        public array $extra = [],
    ) {
    }

    public function isAnonymousVisitor(): bool
    {
        return null === $this->user && null !== $this->visitorId;
    }

    public function isAuthenticatedUser(): bool
    {
        return $this->user instanceof User;
    }
}
