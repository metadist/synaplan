<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Service\Billing\PremiumFeatureGate;
use Psr\Log\LoggerInterface;

/**
 * The API key the WordPress plugin talks to Synaplan with: one active key per
 * account, named after the plugin, and only on a plan that includes API keys.
 */
final readonly class WordPressApiKeyIssuer
{
    public const KEY_NAME = 'WordPress Plugin';

    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private PremiumFeatureGate $premiumFeatureGate,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reuses the account's active plugin key, or mints one.
     *
     * @return array{api_key: string, existing: bool}
     */
    public function issueFor(User $user): array
    {
        $this->premiumFeatureGate->assertApiKeysAllowed($user);

        $userId = (int) $user->getId();
        foreach ($this->apiKeyRepository->findBy(['ownerId' => $userId, 'status' => 'active']) as $existingKey) {
            if (self::KEY_NAME === $existingKey->getName()) {
                $this->logger->debug('WordPress wizard: Reusing existing API key', [
                    'user_id' => $userId,
                ]);

                return ['api_key' => $existingKey->getKey(), 'existing' => true];
            }
        }

        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setOwnerId($userId);
        $apiKey->setName(self::KEY_NAME);
        $apiKey->setKey('sk_live_'.bin2hex(random_bytes(24)));
        $apiKey->setScopes(['widgets', 'api']);
        $apiKey->setStatus('active');

        $this->apiKeyRepository->save($apiKey);

        return ['api_key' => $apiKey->getKey(), 'existing' => false];
    }

    public function ownerOfActiveKey(string $token): ?int
    {
        return $this->apiKeyRepository->findActiveByKey($token)?->getOwnerId();
    }
}
