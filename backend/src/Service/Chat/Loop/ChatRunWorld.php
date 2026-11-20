<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * Loads the person, the chat and the project for one unit. A run whose
 * person or chat is gone, or whose switch is off, gets the reason it ends.
 */
interface ChatRunWorld
{
    public function resolve(ChatRunState $state): ChatRunScope|ChatRunEnd;
}
