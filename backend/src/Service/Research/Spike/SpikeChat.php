<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

interface SpikeChat
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    public function complete(array $messages, array $tools, int $userId): SpikeTurn;
}
