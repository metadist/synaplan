<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Message\NotifyIdentityRevoked;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tells a cloud app that a copy or a revoked link is waiting. The body is
 * only the id; the app fetches the rest with its own credential.
 */
final readonly class DeliveryNotifier
{
    public function __construct(
        private SignedNotificationClient $client,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * The app's answer when it finished inside the request, or null when the
     * copy is still going (or the cloud could not be asked).
     *
     * @return array<string, mixed>|null
     */
    public function delivery(PlatformInstance $instance, int $deliveryId): ?array
    {
        return $this->client->post($instance, 'delivery', (string) $deliveryId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pickup(PlatformInstance $instance, int $pickupId): ?array
    {
        return $this->client->post($instance, 'pickup', (string) $pickupId);
    }

    /**
     * Queues the notice. The caller has already committed the revocation, so
     * a slow or unreachable cloud cannot hold that write open.
     */
    public function identityRevoked(ExternalIdentity $identity): void
    {
        $id = $identity->getId();
        if (null === $id || '' === $identity->getInstanceId()) {
            return;
        }
        $this->bus->dispatch(new NotifyIdentityRevoked($identity->getInstanceId(), (string) $id));
    }
}
