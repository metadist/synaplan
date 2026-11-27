<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\OidcCloud;
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
        $identities->expects(self::once())->method('lock')->willReturn($row);
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
        $identities->expects(self::once())->method('lock')->willReturn($row);
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

    public function testTheSubjectNamesThePersonByDefault(): void
    {
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())
            ->method('insertIfAbsent')
            ->with(4, PlatformInstance::CLIENT_OPENCLOUD, 'person-1', 'oidc-cloud', null, ExternalIdentity::MODE_SSO)
            ->willReturn(null);

        $this->attribution(
            $identities,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LifecycleEffect::class),
            $this->createStub(AuditLogWriter::class),
        )->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
            'preferred_username' => 'ada',
        ]);
    }

    public function testAConfiguredClaimNamesThePersonOnFirstUse(): void
    {
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())
            ->method('insertIfAbsent')
            ->with(4, PlatformInstance::CLIENT_OPENCLOUD, 'ada', 'oidc-cloud', null, ExternalIdentity::MODE_SSO)
            ->willReturn(null);

        $this->attribution(
            $identities,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LifecycleEffect::class),
            $this->createStub(AuditLogWriter::class),
            $this->cloud('preferred_username'),
        )->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
            'preferred_username' => 'ada',
        ]);
    }

    public function testATokenWithoutTheConfiguredClaimCreatesNoLink(): void
    {
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([]);
        $identities->expects(self::never())->method('insertIfAbsent');

        $this->attribution(
            $identities,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LifecycleEffect::class),
            $this->createStub(AuditLogWriter::class),
            $this->cloud('preferred_username'),
        )->attribute($this->user(4), [
            'azp' => 'synaplan-opencloud',
            'sub' => 'person-1',
        ]);
    }

    public function testTheConfiguredClaimBelongsToTheConfiguredCloudOnly(): void
    {
        $cloud = $this->cloud(' preferred_username ');
        $registered = new PlatformInstance(PlatformInstance::CLIENT_OPENCLOUD, 'registered-cloud', 'other.example', null, PlatformInstance::STATUS_ACTIVE);
        $configured = new PlatformInstance(PlatformInstance::CLIENT_OPENCLOUD, PlatformInstance::OIDC_CLOUD_ID, 'cloud.example', null, PlatformInstance::STATUS_ACTIVE);

        self::assertSame('preferred_username', $cloud->userClaimFor($configured));
        self::assertSame('sub', $cloud->userClaimFor($registered));
        self::assertSame('sub', $this->cloud()->userClaimFor($configured), 'unset keeps the subject');
    }

    public function testASsoLinkFollowsAChangedValueAndIsAuditedOnce(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->expects(self::once())
            ->method('findOneByTriple')
            ->with(PlatformInstance::CLIENT_OPENCLOUD, 'oidc-cloud', 'person-2')
            ->willReturn(null);
        $identities->expects(self::once())
            ->method('lock')
            ->with(PlatformInstance::CLIENT_OPENCLOUD, 'oidc-cloud', 'person-1')
            ->willReturn($row);
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::never())->method('onResumed');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')->with(
            4,
            'platform_link.rebound',
            'platform_link',
            '91',
            [
                'client' => PlatformInstance::CLIENT_OPENCLOUD,
                'host' => 'cloud.example',
                'external_id' => 'person-2',
                'previous_external_id' => 'person-1',
            ],
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );
        $attribution = $this->attribution($identities, $entityManager, $effects, $audit);

        $attribution->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);
        $attribution->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);

        self::assertSame('person-2', $row->getExternalId());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $row->getStatus());
    }

    public function testALinkAnotherRequestMovedIsNotAuditedAgain(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())->method('lock')->willReturn(null);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );

        $this->attribution($identities, $entityManager, $this->createStub(LifecycleEffect::class), $audit)
            ->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);
    }

    public function testALinkModeRowIsLeftAlone(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_LINK);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->expects(self::never())->method('lock');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $entityManager->expects(self::once())->method('flush');

        $this->attribution($identities, $entityManager, $this->createStub(LifecycleEffect::class), $audit)
            ->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);

        self::assertSame('person-1', $row->getExternalId());
    }

    public function testAValueThatIsAlreadyAnotherLinkChangesNothing(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $other = new ExternalIdentity();
        $other->setUserId(8);
        $other->setExternalId('person-2');
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->method('findOneByTriple')->willReturn($other);
        $identities->expects(self::never())->method('lock');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $this->attribution($identities, $entityManager, $this->createStub(LifecycleEffect::class), $audit)
            ->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);

        self::assertSame('person-1', $row->getExternalId());
        self::assertSame(8, $other->getUserId());
    }

    public function testAnOffRowStaysOffAndKeepsItsExternalId(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $row->setStatus(ExternalIdentity::STATUS_PAUSED);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->expects(self::never())->method('lock');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        try {
            $this->attribution($identities, $entityManager, $this->createStub(LifecycleEffect::class), $audit)
                ->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);
            self::fail('A link that is off refuses the call.');
        } catch (PlatformDisconnectedException) {
        }

        self::assertSame('person-1', $row->getExternalId());
        self::assertSame(ExternalIdentity::STATUS_PAUSED, $row->getStatus());
    }

    public function testALinkTurnedOffMeanwhileKeepsItsExternalId(): void
    {
        $row = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $locked = $this->link('person-1', ExternalIdentity::MODE_SSO);
        $locked->setStatus(ExternalIdentity::STATUS_PAUSED);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findForUserOnInstance')->willReturn([$row]);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())->method('lock')->willReturn($locked);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );

        $this->attribution($identities, $entityManager, $this->createStub(LifecycleEffect::class), $audit)
            ->attribute($this->user(4), ['azp' => 'synaplan-opencloud', 'sub' => 'person-2']);

        self::assertSame('person-1', $locked->getExternalId());
        self::assertSame(ExternalIdentity::STATUS_PAUSED, $locked->getStatus());
    }

    private function attribution(
        ExternalIdentityRepository $identities,
        EntityManagerInterface $entityManager,
        LifecycleEffect $effects,
        AuditLogWriter $audit,
        ?OidcCloud $cloud = null,
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

        return new PlatformBearerAttribution($instances, $identities, $entityManager, $effects, $audit, $cloud ?? $this->cloud());
    }

    private function cloud(string $userClaim = ''): OidcCloud
    {
        return new OidcCloud(PlatformInstance::CLIENT_OPENCLOUD, 'https://cloud.example', 'synaplan-opencloud', '', $userClaim);
    }

    private function link(string $externalId, string $mode): ExternalIdentity
    {
        $row = new ExternalIdentity();
        $row->setUserId(4);
        $row->setSource(PlatformInstance::CLIENT_OPENCLOUD);
        $row->setInstanceId('oidc-cloud');
        $row->setExternalId($externalId);
        $row->setMode($mode);
        $id = new \ReflectionProperty(ExternalIdentity::class, 'id');
        $id->setValue($row, 91);

        return $row;
    }

    private function user(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }
}
