<?php

declare(strict_types=1);

namespace App\Service\Connection;

/**
 * One connected system as the planner (and the user) should name it.
 *
 * The key is the only identifier that belongs in prompts — never a numeric
 * connection id. Keys are lowercase slugs (`nextcloud`, `calendar`, `folder`).
 */
final readonly class PlannerChannel
{
    public const KIND_FOLDER = 'folder';
    public const KIND_CALENDAR = 'calendar';
    public const KIND_MAIL = 'mail';

    /**
     * @param list<string> $capabilities planner capabilities that may use this channel
     */
    public function __construct(
        public string $key,
        public string $kind,
        public string $label,
        public int $connectionId,
        public array $capabilities,
    ) {
    }
}
