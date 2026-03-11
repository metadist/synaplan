<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Message\ProvisionDefaultUserPluginsMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class UserPluginProvisioningSubscriber
{
    private const SOCIAL_PROVIDERS = ['google', 'github', 'keycloak'];

    /** @var array<string, array{user_id: int, reason: string}> */
    private array $queuedUsers = [];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof User) {
            return;
        }

        if (!$this->shouldQueueForSocialSignup($object)) {
            return;
        }

        $this->queueUser($object->getId(), ProvisionDefaultUserPluginsMessage::REASON_SOCIAL_SIGNUP);
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof User) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($object);
        if (!$this->shouldQueueForEmailVerification($object, $changeSet)) {
            return;
        }

        $this->queueUser($object->getId(), ProvisionDefaultUserPluginsMessage::REASON_EMAIL_VERIFIED);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->queuedUsers) {
            return;
        }

        $queuedUsers = $this->queuedUsers;
        $this->queuedUsers = [];

        foreach ($queuedUsers as $payload) {
            $this->messageBus->dispatch(new ProvisionDefaultUserPluginsMessage(
                $payload['user_id'],
                $payload['reason'],
            ));

            $this->logger->info('Queued default user plugin provisioning', $payload);
        }
    }

    /**
     * @param array<string, mixed> $changeSet
     */
    private function shouldQueueForEmailVerification(User $user, array $changeSet): bool
    {
        if ('local' !== $user->getProviderId()) {
            return false;
        }

        if (!isset($changeSet['emailVerified']) || !is_array($changeSet['emailVerified'])) {
            return false;
        }

        return $changeSet['emailVerified'] === [false, true];
    }

    private function shouldQueueForSocialSignup(User $user): bool
    {
        return $user->isEmailVerified() && in_array($user->getProviderId(), self::SOCIAL_PROVIDERS, true);
    }

    private function queueUser(?int $userId, string $reason): void
    {
        if (null === $userId) {
            return;
        }

        $this->queuedUsers[$userId.':'.$reason] = [
            'user_id' => $userId,
            'reason' => $reason,
        ];
    }
}
