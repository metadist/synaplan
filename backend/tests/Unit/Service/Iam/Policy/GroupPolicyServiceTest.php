<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Policy;

use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\Policy\GroupPolicyService;
use PHPUnit\Framework\TestCase;

final class GroupPolicyServiceTest extends TestCase
{
    private GroupPolicyService $service;

    protected function setUp(): void
    {
        $this->service = new GroupPolicyService(
            $this->createMock(LayeredConfigResolver::class),
            $this->createMock(GroupConfigRepository::class),
            $this->createMock(ConfigRepository::class),
            $this->createMock(ModelRepository::class),
            $this->createMock(AuditLogWriter::class),
        );
    }

    public function testModelIdFromStoredKeepsTestProviderPlaceholderIds(): void
    {
        self::assertSame(-1, $this->service->modelIdFromStored('-1'));
        self::assertSame(-2, $this->service->modelIdFromStored(' -2 '));
    }

    public function testModelIdFromStoredKeepsPositiveBids(): void
    {
        self::assertSame(42, $this->service->modelIdFromStored('42'));
    }

    public function testModelIdFromStoredRejectsZeroAndBlank(): void
    {
        self::assertNull($this->service->modelIdFromStored('0'));
        self::assertNull($this->service->modelIdFromStored(''));
        self::assertNull($this->service->modelIdFromStored('   '));
    }

    public function testModelIdFromStoredResolvesCatalogKeys(): void
    {
        self::assertSame(
            249,
            $this->service->modelIdFromStored('anthropic:claude-sonnet-5:chat'),
        );
        self::assertNull($this->service->modelIdFromStored('not-a-catalog-key'));
    }
}
