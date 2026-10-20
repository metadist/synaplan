<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\Federation\FederationSigner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One signed notification. Redirects are not followed: the origin check
 * applies to the address that was stored, not to wherever it points next.
 */
final readonly class SignedNotificationClient
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private HttpClientInterface $http,
        private NotifyKeyStore $keys,
        private FederationSigner $signer,
        private DeliveryOriginPolicy $origins,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function post(PlatformInstance $instance, string $type, string $id): ?array
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
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
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
