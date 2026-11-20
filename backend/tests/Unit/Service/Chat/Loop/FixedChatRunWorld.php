<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunScope;
use App\Service\Chat\Loop\ChatRunState;
use App\Service\Chat\Loop\ChatRunWorld;

/** The world a test sets: a scope, or the reason the run may not go on. */
final class FixedChatRunWorld implements ChatRunWorld
{
    public function __construct(public ChatRunScope|ChatRunEnd $now)
    {
    }

    public function resolve(ChatRunState $state): ChatRunScope|ChatRunEnd
    {
        return $this->now;
    }
}
