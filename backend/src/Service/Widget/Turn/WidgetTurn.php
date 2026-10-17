<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;

/**
 * One visitor message the gate admitted: the widget, its owner and
 * configuration, the visitor's session and what they sent.
 */
final readonly class WidgetTurn
{
    /**
     * @param array<string, mixed> $config          the widget's configuration
     * @param array<mixed>         $files           the file ids the visitor sent, unchecked
     * @param bool                 $heldForOperator a person runs the session (human or waiting mode), so the AI stays out
     */
    public function __construct(
        public Widget $widget,
        public User $owner,
        public WidgetSession $session,
        public array $config,
        public string $text,
        public array $files,
        public ?int $chatId,
        public string $externalUserId,
        public bool $heldForOperator,
    ) {
    }
}
