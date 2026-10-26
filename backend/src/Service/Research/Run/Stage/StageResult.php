<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

final readonly class StageResult
{
    /** @var list<string> */
    public const NAMES = ['plan', 'gather', 'read', 'round', 'write', 'check', 'deliver', 'wait_answer', 'wait_plan', 'done'];

    public function __construct(public string $next)
    {
        if (!in_array($next, self::NAMES, true)) {
            throw new \InvalidArgumentException('Unknown research step.');
        }
    }
}
