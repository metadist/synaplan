<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final class FixedToolSource implements ToolSourceInterface
{
    /** @param list<ToolDescriptor> $tools */
    public function __construct(private array $tools)
    {
    }

    public function source(): ToolSource
    {
        return ToolSource::Builtin;
    }

    public function describe(int $userId, array $context = []): array
    {
        return $this->tools;
    }
}
