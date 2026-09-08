<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\Rerank\RerankRegistry;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class RerankRegistryTest extends TestCase
{
    public function testActiveIsNullWhileRerankStaysDisabled(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        $registry = new RerankRegistry([], new PlugConfigService($repo));

        $this->assertNull($registry->active());
        $this->assertSame([], $registry->all());
    }
}
