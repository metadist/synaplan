<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Policy;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\Exception\MissingInstanceDefaultException;
use App\Service\Iam\Policy\GroupPolicyService;
use PHPUnit\Framework\TestCase;

final class GroupPolicyServiceTest extends TestCase
{
    private GroupPolicyService $service;

    protected function setUp(): void
    {
        $this->service = new GroupPolicyService(
            $this->createStub(LayeredConfigResolver::class),
            $this->createStub(GroupConfigRepository::class),
            $this->createStub(ConfigRepository::class),
            $this->createStub(ModelRepository::class),
            $this->createStub(AuditLogWriter::class),
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

    public function testSetLocksThrowsWhenGlobalRowIsMissing(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::once())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'DEFAULTMODEL', 'CHAT')
            ->willReturn(null);
        $configRepository->expects(self::never())->method('setValue');
        $configRepository->expects(self::never())->method('save');

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        $this->expectException(MissingInstanceDefaultException::class);
        $service->setLocks(['DEFAULTMODEL.CHAT' => true], $actor);
    }

    public function testSetLocksUnlocksMissingRowAsNoOp(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::once())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'DEFAULTMODEL', 'CHAT')
            ->willReturn(null);
        $configRepository->expects(self::never())->method('setValue');
        $configRepository->expects(self::never())->method('save');

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        $result = $service->setLocks(['DEFAULTMODEL.CHAT' => false], $actor);
        self::assertSame(['DEFAULTMODEL.CHAT' => false], $result);
    }

    public function testSetLocksSetsBlockedOnExistingRow(): void
    {
        $row = new Config();
        $row->setOwnerId(0);
        $row->setGroup('DEFAULTMODEL');
        $row->setSetting('CHAT');
        $row->setValue('anthropic:claude-sonnet-5:chat');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::once())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'DEFAULTMODEL', 'CHAT')
            ->willReturn($row);
        $configRepository->expects(self::once())
            ->method('save')
            ->with($row);

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        $result = $service->setLocks(['DEFAULTMODEL.CHAT' => true], $actor);
        self::assertTrue($row->isBlocked());
        self::assertSame(['DEFAULTMODEL.CHAT' => true], $result);
    }

    private function serviceWith(
        ConfigRepository $configRepository,
        AuditLogWriter $audit,
    ): GroupPolicyService {
        return new GroupPolicyService(
            $this->createStub(LayeredConfigResolver::class),
            $this->createStub(GroupConfigRepository::class),
            $configRepository,
            $this->createStub(ModelRepository::class),
            $audit,
        );
    }
}
