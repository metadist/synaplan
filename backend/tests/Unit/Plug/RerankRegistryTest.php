<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\Rerank\RerankRegistry;
use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;

final class RerankRegistryTest extends TestCase
{
    public function testActiveIsNullWhileRerankStaysDisabled(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(null);

        $registry = new RerankRegistry([], new PlugConfigService($repo), $models);

        $this->assertNull($registry->active());
        $this->assertSame([], $registry->all());
    }
}
