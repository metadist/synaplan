<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Credential;
use App\Repository\CredentialRepository;
use App\Service\Credential\CredentialVaultInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Every secret the account still owns, forgotten through the vault inside
 * the transaction. Runs last: a domain that reads its secret on the way out
 * (the Telegram webhook drop needs the bot token) forgets it itself first,
 * and a credential no domain claims any more goes here.
 */
#[AsTaggedItem(priority: 50)]
final readonly class CredentialEraser implements UserDataEraserInterface
{
    public function __construct(
        private CredentialRepository $credentials,
        private CredentialVaultInterface $vault,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->credentials->findBy(['ownerId' => $erasure->userId]) as $credential) {
            $credentialId = $credential->getId();
            if (null !== $credentialId) {
                $this->vault->forget($credentialId, $erasure->userId);
            }
        }
    }

    public static function covers(): array
    {
        return [Credential::class];
    }
}
