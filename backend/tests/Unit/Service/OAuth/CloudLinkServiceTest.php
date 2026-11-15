<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\OAuth\Server\CloudGrantMailer;
use App\Service\OAuth\Server\CloudLinkRefused;
use App\Service\OAuth\Server\CloudLinkService;
use App\Service\PlatformLink\LifecycleEffect;
use App\Service\PlatformLink\PlatformLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CloudLinkServiceTest extends TestCase
{
    public function testAConcurrentManagedAccountIsRefused(): void
    {
        $winner = $this->identity(9, ExternalIdentity::MODE_SSO);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())->method('insertIfAbsent')->willReturn(null);
        $identities->expects(self::never())->method('upsert');
        $identities->expects(self::once())->method('lock')->willReturn($winner);
        $tokens = $this->createMock(OAuthTokenRepository::class);
        $tokens->expects(self::never())->method('revokeForAccount');
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::never())->method('onDeleted');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $mail = $this->createMock(CloudGrantMailer::class);
        $mail->expects(self::never())->method('notify');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );
        $entityManager->expects(self::never())->method('flush');

        try {
            $this->service($identities, $tokens, $effects, $audit, $mail, $entityManager)->link(4, $this->instance(), 'ext-1', '127.0.0.1');
            self::fail('A managed account that won the insert must be refused.');
        } catch (CloudLinkRefused) {
            self::assertSame(9, $winner->getUserId());
            self::assertSame(ExternalIdentity::MODE_SSO, $winner->getMode());
        }
    }

    public function testAConcurrentLinkMovesOnlyThroughTheTransfer(): void
    {
        $winner = $this->identity(9, ExternalIdentity::MODE_LINK);
        $winner->setApiKeyId(3);
        $identities = $this->createMock(ExternalIdentityRepository::class);
        $identities->method('findOneByTriple')->willReturn(null);
        $identities->expects(self::once())->method('insertIfAbsent')->willReturn(null);
        $identities->expects(self::never())->method('upsert');
        $identities->expects(self::once())->method('lock')->willReturn($winner);
        $tokens = $this->createMock(OAuthTokenRepository::class);
        $tokens->expects(self::once())->method('revokeForAccount')->with('oidc-cloud', 9, 'ext-1');
        $keys = $this->createMock(ApiKeyRepository::class);
        $keys->expects(self::once())->method('find')->with(3)->willReturn(null);
        $effects = $this->createMock(LifecycleEffect::class);
        $effects->expects(self::once())->method('onDeleted')->with($winner);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::exactly(2))->method('record');
        $mail = $this->createMock(CloudGrantMailer::class);
        $mail->expects(self::exactly(2))->method('notify');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $work): mixed => $work(),
        );
        $entityManager->expects(self::once())->method('flush');

        $linked = $this->service($identities, $tokens, $effects, $audit, $mail, $entityManager, $keys)->link(
            4,
            $this->instance(),
            'ext-1',
            '127.0.0.1',
        );

        self::assertSame(4, $linked->getUserId());
        self::assertSame(ExternalIdentity::MODE_LINK, $linked->getMode());
        self::assertNull($linked->getApiKeyId());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $linked->getStatus());
    }

    private function service(
        ExternalIdentityRepository $identities,
        OAuthTokenRepository $tokens,
        LifecycleEffect $effects,
        AuditLogWriter $audit,
        CloudGrantMailer $mail,
        EntityManagerInterface $entityManager,
        ?ApiKeyRepository $keys = null,
    ): CloudLinkService {
        return new CloudLinkService(
            $identities,
            $tokens,
            $keys ?? $this->createStub(ApiKeyRepository::class),
            $effects,
            $audit,
            $this->createStub(PlatformLifecycleService::class),
            $mail,
            $entityManager,
        );
    }

    private function instance(): PlatformInstance
    {
        return new PlatformInstance(
            PlatformInstance::CLIENT_OPENCLOUD,
            'oidc-cloud',
            'cloud.example',
            null,
            PlatformInstance::STATUS_ACTIVE,
        );
    }

    private function identity(int $userId, string $mode): ExternalIdentity
    {
        $identity = new ExternalIdentity();
        $identity->setUserId($userId);
        $identity->setSource(PlatformInstance::CLIENT_OPENCLOUD);
        $identity->setInstanceId('oidc-cloud');
        $identity->setExternalId('ext-1');
        $identity->setMode($mode);
        $id = new \ReflectionProperty(ExternalIdentity::class, 'id');
        $id->setValue($identity, 15);

        return $identity;
    }
}
