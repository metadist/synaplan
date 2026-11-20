<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\User;
use App\Service\Project\ProjectTurnContext;

/**
 * What one unit of a chat run may use, resolved again at every unit: the
 * person, the chat, and the chat's project as it is now.
 */
final readonly class ChatRunScope
{
    public function __construct(
        public User $user,
        public Chat $chat,
        public ?ProjectTurnContext $project,
    ) {
    }
}
