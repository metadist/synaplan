<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Message\NotifyIdentityRevoked;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\PlatformLink\SignedNotificationClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NotifyIdentityRevokedHandler
{
    public function __construct(
        private PlatformInstanceRepository $instances,
        private ExternalIdentityRepository $identities,
        private SignedNotificationClient $client,
    ) {
    }

    public function __invoke(NotifyIdentityRevoked $message): void
    {
        $identity = $this->identities->find((int) $message->identityId);
        if (!$identity instanceof ExternalIdentity || ExternalIdentity::STATUS_ACTIVE === $identity->getStatus()) {
            return;
        }
        if ($message->revokedAt > 0 && (int) ($identity->getRevokedAt() ?? 0) !== $message->revokedAt) {
            return;
        }
        $instance = $this->instances->findByInstanceId($message->instanceId);
        if (!$instance instanceof PlatformInstance) {
            return;
        }
        $this->client->post($instance, 'identity_revoked', $message->identityId);
    }
}
