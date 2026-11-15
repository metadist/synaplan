<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\PlatformBearerAttribution;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PlatformBearerAttributionTest extends TestCase
{
    public function testAnExistingLinkIsNotResumedOrAudited(): void
    {
        $existing = new ExternalIdentity();
        $existing->setUserId(8);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn($existing);
        $identities->expects(self::never())->method('insertIfAbsent');
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::never())->method('onResumed');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->attribution($identities, $entityManager, $effects, $audit)->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
        ]);
    }

    public function testALostInsertDoesNotResumeOrAudit(): void
    {
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())
            ->method('insertIfAbsent')
            ->with(4, PlatformInstance::CLIENT_OPENCLOUD, 'person-1', 'oidc-cloud', null, ExternalIdentity::MODE_SSO)
            ->willReturn(null);
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::never())->method('onResumed');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->attribution($identities, $entityManager, $effects, $audit)->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
        ]);
    }

    public function testTheInsertingRequestResumesAndAuditsOnce(): void
    {
        $row = new ExternalIdentity();
        $row->setUserId(4);
        $row->setSource(PlatformInstance::CLIENT_OPENCLOUD);
        $row->setInstanceId('oidc-cloud');
        $row->setExternalId('person-1');
        $row->setMode(ExternalIdentity::MODE_SSO);
        $id = new \ReflectionProperty(ExternalIdentity::class, 'id');
        $id->setValue($row, 91);

        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())->method('insertIfAbsent')->willReturn($row);
        $identities->expects(self::once())->method('current')->willReturn($row);
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::once())->method('onResumed')->with($row);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')->with(
            4,
            'platform_link.bound',
            'platform_link',
            '91',
            [
                'client' => PlatformInstance::CLIENT_OPENCLOUD,
                'host' => 'cloud.example',
                'external_id' => 'person-1',
                'mode' => ExternalIdentity::MODE_SSO,
            ],
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );
        $entityManager->expects(self::once())->method('flush');

        $this->attribution($identities, $entityManager, $effects, $audit)->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
        ]);

        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $row->getStatus());
    }

    public function testAnOffRowIsNotTurnedBackOn(): void
    {
        $row = new ExternalIdentity();
        $row->setUserId(4);
        $row->setStatus(ExternalIdentity::STATUS_REVOKED);
        $id = new \ReflectionProperty(ExternalIdentity::class, 'id');
        $id->setValue($row, 91);

        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->method('insertIfAbsent')->willReturn($row);
        $identities->expects(self::once())->method('current')->willReturn($row);
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::never())->method('onResumed');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );
        $entityManager->expects(self::never())->method('flush');

        $this->attribution($identities, $entityManager, $effects, $audit)->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
        ]);

        self::assertSame(ExternalIdentity::STATUS_REVOKED, $row->getStatus());
    }

    private function attribution(
        ExternalIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        LifecycleEffect $effects,
        AuditLogWriter $audit,
    ): PlatformBearerAttribution {
        $instances = $this->createStub(PlatformInstanceRepository::class);
        $instances->method('findByOidcClientId')->willReturn([
            new PlatformInstance(
                PlatformInstance::CLIENT_OPENCLOUD,
                'oidc-cloud',
                'cloud.example',
                null,
                PlatformInstance::STATUS_ACTIVE,
            ),
        ]);

        return new PlatformBearerAttribution($instances, $identities, $entityManager, $effects, $audit);
    }

    private function user(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
