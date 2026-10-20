<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\Federation\FederationSigner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tells a cloud app that a copy or a revoked link is waiting. The body is
 * only the id; the app fetches the rest with its own credential.
 */
final class DeliveryNotifier
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private HttpClientInterface $http,
        private PlatformInstanceRepository $instances,
        private NotifyKeyStore $keys,
        private FederationSigner $signer,
        private DeliveryOriginPolicy $origins,
        private LoggerInterface $logger,
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
        return $this->post($instance, 'delivery', (string) $deliveryId);
    }

    public function identityRevoked(ExternalIdentity $identity): void
    {
        $id = $identity->getId();
        if (null === $id || '' === $identity->getInstanceId()) {
            return;
        }
        $instance = $this->instances->findByInstanceId($identity->getInstanceId());
        if (!$instance instanceof PlatformInstance) {
            return;
        }
        $this->post($instance, 'identity_revoked', (string) $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function post(PlatformInstance $instance, string $type, string $id): ?array
    {
        $url = $instance->getDeliveryUrl();
        if (null === $url || '' === $url || !$this->origins->accepts($instance, $url)) {
            return null;
        }
        $signing = $this->keys->signingKey();
        $body = $this->signer->signBody([
            'type' => $type,
            'id' => $id,
            'instance_id' => $instance->getInstanceId(),
            'ts' => time(),
            'kid' => $this->signer->fingerprint($signing['publicKey']),
        ], $signing['secretKey']);

        try {
            $response = $this->http->request('POST', $url, [
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                return null;
            }
            $decoded = json_decode($response->getContent(false), true);

            return is_array($decoded) ? $decoded : null;
        } catch (ExceptionInterface $e) {
            $this->logger->info('Cloud notification was not answered in time', [
                'instance' => $instance->getInstanceId(),
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
