<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\PlatformInstanceSupersession;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PlatformInstanceSupersessionTest extends TestCase
{
    private PlatformInstance $older;
    private PlatformInstance $newer;
    private PlatformInstanceRepository&MockObject $platforms;
    private ExternalIdentityRepository&MockObject $identities;
    private ApiKeyRepository&MockObject $apiKeys;
    private EntityManagerInterface&MockObject $entityManager;
    private Connection&MockObject $connection;
    private AuditLogWriter&MockObject $auditLog;

    protected function setUp(): void
    {
        $this->older = $this->instance(10, 'pi_old', PlatformInstance::STATUS_ACTIVE);
        $this->newer = $this->instance(11, 'pi_new', PlatformInstance::STATUS_ACTIVE);
        $this->platforms = $this->createMock(PlatformInstanceRepository::class);
        $this->identities = $this->createMock(ExternalIdentityRepository::class);
        $this->apiKeys = $this->createMock(ApiKeyRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $work): mixed => $work());
        $this->connection = $this->createMock(Connection::class);
        $this->auditLog = $this->createMock(AuditLogWriter::class);
    }

    public function testTheNewerRegistrationTakesOverTheOlderTwin(): void
    {
        $this->platforms->expects($this->once())->method('findBy')
            ->with(['oidcClientId' => 'synaplan-opencloud', 'host' => 'cloud.example.org'])
            ->willReturn([$this->older, $this->newer]);

        // Ada only ever connected to the older instance: her row moves with its key.
        $ada = $this->identity(1, 'pi_old', 'ada', apiKeyId: 501);
        // Bob already connected to the newer one: the older row and its key go, his watches follow.
        $bobOld = $this->identity(2, 'pi_old', 'bob', apiKeyId: 502);
        $bobNew = $this->identity(3, 'pi_new', 'bob', apiKeyId: 503);
        $this->identities->method('findByInstanceId')->willReturnMap([
            ['pi_new', [$bobNew]],
            ['pi_old', [$ada, $bobOld]],
        ]);
        $bobOldKey = $this->createStub(ApiKey::class);
        $this->apiKeys->expects($this->once())->method('find')->with(502)->willReturn($bobOldKey);
        $this->apiKeys->expects($this->once())->method('remove')->with($bobOldKey, false);
        $this->identities->expects($this->once())->method('remove')->with($bobOld, false);

        $statements = [];
        $this->connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$statements): int {
                $statements[] = [$sql, $params];

                return 1;
            }
        );
        $this->auditLog->expects($this->once())->method('record')->with(
            0,
            'platform_instance.superseded',
            'platform_instance',
            'pi_old',
            ['by' => 'pi_new', 'host' => 'cloud.example.org', 'identities_moved' => 1, 'identities_merged' => 1],
        );

        $superseded = $this->service()->takeOverOlderTwins($this->newer);

        self::assertSame(['pi_old'], $superseded);
        self::assertSame('pi_new', $ada->getInstanceId(), 'Ada follows to the newer instance');
        self::assertSame(PlatformInstance::STATUS_REVOKED, $this->older->getStatus());
        self::assertTrue($this->newer->isActive());

        $sql = array_column($statements, 0);
        self::assertContains('UPDATE BCLOUDWATCHES SET BIDENTITYID = :twin WHERE BIDENTITYID = :older', $sql);
        self::assertContains('UPDATE BFILES SET BSOURCEINSTANCE = :newer WHERE BSOURCEINSTANCE = :older', $sql);
        self::assertContains('UPDATE BCLOUDPICKUPS SET BINSTANCEID = :newer WHERE BINSTANCEID = :older', $sql);
        self::assertSame(['twin' => 3, 'older' => 2], $statements[0][1], "Bob's watches move to his newer identity");
    }

    public function testAnOlderInstanceAnnouncingTakesNothingOver(): void
    {
        $this->platforms->method('findBy')->willReturn([$this->older, $this->newer]);
        $this->entityManager->expects($this->never())->method('wrapInTransaction');
        $this->auditLog->expects($this->never())->method('record');

        self::assertSame([], $this->service()->takeOverOlderTwins($this->older));
        self::assertTrue($this->newer->isActive());
        self::assertTrue($this->older->isActive());
    }

    public function testATwinAnotherAnnouncementAlreadyTookOverIsSkipped(): void
    {
        $this->platforms->method('findBy')->willReturn([$this->older, $this->newer]);
        // The re-read inside the lock shows the twin is already revoked.
        $this->entityManager->method('refresh')->willReturnCallback(static function (PlatformInstance $instance): void {
            $instance->setStatus(PlatformInstance::STATUS_REVOKED);
        });
        $this->connection->expects($this->never())->method('executeStatement');
        $this->auditLog->expects($this->never())->method('record');

        self::assertSame([], $this->service()->takeOverOlderTwins($this->newer));
    }

    public function testAPendingOrClientlessInstanceTakesNothingOver(): void
    {
        $this->platforms->expects($this->never())->method('findBy');

        $pending = $this->instance(12, 'pi_pending', PlatformInstance::STATUS_PENDING);
        self::assertSame([], $this->service()->takeOverOlderTwins($pending));

        $this->newer->setOidcClientId(null);
        self::assertSame([], $this->service()->takeOverOlderTwins($this->newer));
    }

    private function service(): PlatformInstanceSupersession
    {
        return new PlatformInstanceSupersession(
            $this->platforms,
            $this->identities,
            $this->apiKeys,
            $this->entityManager,
            $this->connection,
            $this->auditLog,
        );
    }

    private function instance(int $id, string $instanceId, string $status): PlatformInstance
    {
        $instance = new PlatformInstance('opencloud', $instanceId, 'cloud.example.org', null, $status);
        $instance->setOidcClientId('synaplan-opencloud');
        $this->setId($instance, $id);

        return $instance;
    }

    private function identity(int $id, string $instanceId, string $externalId, int $apiKeyId): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        $identity->setSource('opencloud');
        $identity->setExternalId($externalId);
        $identity->setInstanceId($instanceId);
        $identity->setApiKeyId($apiKeyId);
        $this->setId($identity, $id);

        return $identity;
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
