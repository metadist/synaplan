<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\UserPluginProvisioningSubscriber;
use App\Message\ProvisionDefaultUserPluginsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class UserPluginProvisioningSubscriberTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private EntityManagerInterface&MockObject $entityManager;
    private UnitOfWork&MockObject $unitOfWork;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWork::class);

        $this->entityManager->method('getUnitOfWork')->willReturn($this->unitOfWork);
    }

    public function testQueuesProvisioningForVerifiedSocialSignup(): void
    {
        $user = $this->createUser(5, 'google', true);
        $subscriber = new UserPluginProvisioningSubscriber($this->messageBus, $this->logger);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (mixed $message): bool {
                return $message instanceof ProvisionDefaultUserPluginsMessage
                    && 5 === $message->getUserId()
                    && ProvisionDefaultUserPluginsMessage::REASON_SOCIAL_SIGNUP === $message->getReason();
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $subscriber->postPersist(new PostPersistEventArgs($user, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testQueuesProvisioningWhenLocalUserVerifiesEmail(): void
    {
        $user = $this->createUser(8, 'local', true);
        $subscriber = new UserPluginProvisioningSubscriber($this->messageBus, $this->logger);

        $this->unitOfWork->expects($this->once())
            ->method('getEntityChangeSet')
            ->with($user)
            ->willReturn([
                'emailVerified' => [false, true],
            ]);

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (mixed $message): bool {
                return $message instanceof ProvisionDefaultUserPluginsMessage
                    && 8 === $message->getUserId()
                    && ProvisionDefaultUserPluginsMessage::REASON_EMAIL_VERIFIED === $message->getReason();
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $subscriber->postUpdate(new PostUpdateEventArgs($user, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    public function testDoesNotQueueProvisioningForVerifiedLocalUserOnCreate(): void
    {
        $user = $this->createUser(13, 'local', true);
        $subscriber = new UserPluginProvisioningSubscriber($this->messageBus, $this->logger);

        $this->messageBus->expects($this->never())->method('dispatch');

        $subscriber->postPersist(new PostPersistEventArgs($user, $this->entityManager));
        $subscriber->postFlush(new PostFlushEventArgs($this->entityManager));
    }

    private function createUser(int $id, string $providerId, bool $emailVerified): User
    {
        $user = new User();
        $user->setProviderId($providerId);
        $user->setEmailVerified($emailVerified);

        $reflection = new \ReflectionProperty($user, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);

        return $user;
    }
}
