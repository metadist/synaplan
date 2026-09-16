<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Policy;

use App\Entity\Config;
use App\Entity\GroupConfig;
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

    public function testConflictsNumericModelIdsStayJsonStrings(): void
    {
        $rowA = new GroupConfig();
        $rowA->setValue('-10');
        $rowB = new GroupConfig();
        $rowB->setValue('-7');

        $groupConfigs = $this->createMock(GroupConfigRepository::class);
        $groupConfigs->method('getValue')->willReturn(null);
        $groupConfigs->method('findByGroupAndSetting')->willReturnCallback(
            static function (string $group, string $setting) use ($rowA, $rowB): array {
                if ('DEFAULTMODEL' === $group && 'CHAT' === $setting) {
                    return [$rowA, $rowB];
                }

                return [];
            },
        );

        $resolver = $this->createMock(LayeredConfigResolver::class);
        $resolver->method('isLocked')->willReturn(false);

        $service = new GroupPolicyService(
            $resolver,
            $groupConfigs,
            $this->createMock(ConfigRepository::class),
            $this->createMock(ModelRepository::class),
            $this->createMock(AuditLogWriter::class),
        );

        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn(1);

        $payload = $service->getGroupConfig(1, $actor);
        $conflicts = (array) $payload['conflicts'];
        self::assertArrayHasKey('DEFAULTMODEL.CHAT', $conflicts);
        self::assertSame(['-10', '-7'], $conflicts['DEFAULTMODEL.CHAT']);

        $json = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"DEFAULTMODEL.CHAT":["-10","-7"]', $json);
        self::assertStringNotContainsString('[-10,-7]', $json);
        self::assertStringContainsString('"conflicts":{', $json);
        self::assertStringNotContainsString('"conflicts":[]', $json);
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

    public function testSetLocksDoesNotPersistWhenALaterKeyIsMissing(): void
    {
        $chat = new Config();
        $chat->setOwnerId(0);
        $chat->setGroup('DEFAULTMODEL');
        $chat->setSetting('CHAT');
        $chat->setValue('anthropic:claude-sonnet-5:chat');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::exactly(2))
            ->method('findByOwnerGroupAndSetting')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting) use ($chat): ?Config {
                self::assertSame(0, $ownerId);
                if ('DEFAULTMODEL' === $group && 'CHAT' === $setting) {
                    return $chat;
                }
                if ('RATELIMITS' === $group && 'TIER' === $setting) {
                    return null;
                }

                throw new \LogicException(sprintf('Unexpected lookup %s.%s', $group, $setting));
            });
        $configRepository->expects(self::never())->method('save');

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        try {
            $service->setLocks([
                'DEFAULTMODEL.CHAT' => true,
                'RATELIMITS.TIER' => true,
            ], $actor);
            self::fail('Expected MissingInstanceDefaultException');
        } catch (MissingInstanceDefaultException) {
            self::assertFalse($chat->isBlocked());
        }
    }

    public function testSetLocksTreatsBlankValueAsMissing(): void
    {
        $row = new Config();
        $row->setOwnerId(0);
        $row->setGroup('DEFAULTMODEL');
        $row->setSetting('CHAT');
        $row->setValue('   ');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::once())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'DEFAULTMODEL', 'CHAT')
            ->willReturn($row);
        $configRepository->expects(self::never())->method('save');

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        $this->expectException(MissingInstanceDefaultException::class);
        $service->setLocks(['DEFAULTMODEL.CHAT' => true], $actor);
    }

    public function testSetLocksAllowsEmptyModelsAllowedList(): void
    {
        $row = new Config();
        $row->setOwnerId(0);
        $row->setGroup('MODELS');
        $row->setSetting('ALLOWED');
        $row->setValue('');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects(self::once())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, 'MODELS', 'ALLOWED')
            ->willReturn($row);
        $configRepository->expects(self::once())
            ->method('save')
            ->with($row);

        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record');

        $service = $this->serviceWith($configRepository, $audit);
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(1);

        $result = $service->setLocks(['MODELS.ALLOWED' => true], $actor);
        self::assertTrue($row->isBlocked());
        self::assertSame(['MODELS.ALLOWED' => true], $result);
    }

    public function testSetLocksUnlocksBlankLegacyRow(): void
    {
        $row = new Config();
        $row->setOwnerId(0);
        $row->setGroup('DEFAULTMODEL');
        $row->setSetting('CHAT');
        $row->setValue('');
        $row->setBlocked(true);

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

        $result = $service->setLocks(['DEFAULTMODEL.CHAT' => false], $actor);
        self::assertFalse($row->isBlocked());
        self::assertSame(['DEFAULTMODEL.CHAT' => false], $result);
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
