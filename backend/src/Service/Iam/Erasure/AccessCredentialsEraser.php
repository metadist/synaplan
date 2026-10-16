<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\ApiKey;
use App\Entity\Session;
use App\Entity\Token;
use App\Entity\VerificationToken;
use App\Repository\ApiKeyRepository;
use App\Repository\SessionRepository;
use App\Repository\TokenRepository;
use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 200)]
final readonly class AccessCredentialsEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private VerificationTokenRepository $verificationTokens,
        private TokenRepository $tokens,
        private ApiKeyRepository $apiKeys,
        private SessionRepository $sessions,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $rows = [
            ...$this->verificationTokens->findBy(['userId' => $erasure->userId]),
            ...$this->tokens->findBy(['userId' => $erasure->userId]),
            ...$this->apiKeys->findBy(['ownerId' => $erasure->userId]),
            ...$this->sessions->findBy(['userId' => $erasure->userId]),
        ];
        foreach ($rows as $row) {
            $this->em->remove($row);
        }
    }

    public static function covers(): array
    {
        return [VerificationToken::class, Token::class, ApiKey::class, Session::class];
    }
}
