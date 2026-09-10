<?php

declare(strict_types=1);

namespace App\Service\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.tool.source')]
interface ToolSourceInterface
{
    public function source(): ToolSource;

    /**
     * @param array<string, mixed> $context
     *
     * @return list<ToolDescriptor>
     */
    public function describe(int $userId, array $context = []): array;
}
