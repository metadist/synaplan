<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AdminUserProvisioningService;
use App\Service\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AdminUserProvisioningServiceTest extends TestCase
{
    private UserRepository&Stub $userRepository;
    private ApiKeyRepository&MockObject $apiKeyRepository;
    private EntityManagerInterface&Stub $em;
    private UserLifecycleService&Stub $userLifecycle;
    private AdminUserProvisioningService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createStub(UserRepository::class);
        $this->apiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->userLifecycle = $this->createStub(UserLifecycleService::class);

        $this->service = new AdminUserProvisioningService(
            $this->userRepository,
            $this->apiKeyRepository,
            $this->em,
            $this->userLifecycle,
            new NullLogger(),
        );
    }

    public function testProvisionRejectsEmptySource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->provision('', 'ext-1', 'a@b.test');
    }

    public function testProvisionRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->provision('nextcloud', 'ext-1', 'not-an-email');
    }

    public function testProvisionRejectsInvalidLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->provision('nextcloud', 'ext-1', 'a@b.test', 'Name', 'ADMIN');
    }

    public function testProvisionRejectsTooShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->provision('e2e', 'ext-1', 'a@b.test', null, 'NEW', 'short');
    }

    public function testProvisionPassesPasswordToLifecycleService(): void
    {
        $lifecycle = $this->createMock(UserLifecycleService::class);
        $lifecycle->expects($this->once())
            ->method('createUser')
            ->with(
                'a@b.test',
                'SecurePass123!',
                AdminUserProvisioningService::PROVIDER_ID,
                'WEB',
                'NEW',
                true,
                $this->isArray(),
            )
            ->willReturn($this->userWithId(151));

        $service = new AdminUserProvisioningService(
            $this->userRepository,
            $this->apiKeyRepository,
            $this->em,
            $lifecycle,
            new NullLogger(),
        );

        $result = $service->provision('e2e', 'ext-1', 'a@b.test', null, 'NEW', 'SecurePass123!');

        $this->assertTrue($result['created']);
        $this->assertSame(151, $result['user']->getId());
    }

    public function testMintApiKeyGeneratesPrefixedKeyWithScopes(): void
    {
        $this->apiKeyRepository->expects($this->once())
            ->method('save')
            ->with($this->isInstanceOf(ApiKey::class));

        $user = $this->userWithId(149);
        $result = $this->service->mintApiKeyForUser($user, 'nc-alice', ['chat', 'files']);

        $this->assertStringStartsWith('sk_', $result['plainKey']);
        $this->assertSame(['chat', 'files'], $result['entity']->getScopes());
        $this->assertSame('nc-alice', $result['entity']->getName());
        $this->assertSame('active', $result['entity']->getStatus());
    }

    public function testMintApiKeyDefaultsToWildcardScope(): void
    {
        $this->apiKeyRepository->method('save');

        $result = $this->service->mintApiKeyForUser($this->userWithId(150), '', []);

        $this->assertSame(['*'], $result['entity']->getScopes());
        $this->assertSame('external-integration', $result['entity']->getName());
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
