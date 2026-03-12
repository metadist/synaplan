<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\ProvisionDefaultUserPluginsMessage;
use App\Service\Plugin\DefaultUserPluginProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProvisionDefaultUserPluginsMessageHandler
{
    private const SOCIAL_PROVIDERS = ['google', 'github', 'keycloak'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DefaultUserPluginProvisioner $provisioner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProvisionDefaultUserPluginsMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->getUserId());
        if (!$user instanceof User) {
            $this->logger->warning('Skipping default plugin provisioning because user no longer exists', [
                'user_id' => $message->getUserId(),
                'reason' => $message->getReason(),
            ]);

            return;
        }

        if (!$this->shouldProvision($user, $message)) {
            $this->logger->debug('Skipping queued default plugin provisioning because user is not eligible', [
                'user_id' => $user->getId(),
                'provider' => $user->getProviderId(),
                'verified' => $user->isEmailVerified(),
                'reason' => $message->getReason(),
            ]);

            return;
        }

        $this->provisioner->provisionNewUser($user);
    }

    private function shouldProvision(User $user, ProvisionDefaultUserPluginsMessage $message): bool
    {
        return match ($message->getReason()) {
            ProvisionDefaultUserPluginsMessage::REASON_SOCIAL_SIGNUP => $user->isEmailVerified() && in_array($user->getProviderId(), self::SOCIAL_PROVIDERS, true),
            ProvisionDefaultUserPluginsMessage::REASON_EMAIL_VERIFIED => $user->isEmailVerified() && 'local' === $user->getProviderId(),
            default => false,
        };
    }
}
