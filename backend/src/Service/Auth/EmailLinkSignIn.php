<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Sign in with a one-time link sent by mail (PF3).
 *
 * The request answers the same whether or not the address has an account, so
 * it cannot be used to test addresses. The link works once, for 15 minutes;
 * only its hash is stored. Opening it proves the person reads that mailbox,
 * so it also marks the address verified.
 */
final readonly class EmailLinkSignIn
{
    public const TOKEN_TYPE = 'login_link';
    public const TTL_SECONDS = 900;

    public function __construct(
        private UserRepository $users,
        private VerificationTokenRepository $tokens,
        private LoginLinkMailer $mailer,
        private EntityManagerInterface $em,
        #[Autowire(service: 'limiter.email_link_ip')]
        private RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.email_link_address')]
        private RateLimiterFactoryInterface $addressLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws EmailLinkRateLimited
     */
    public function request(string $email, ?string $clientIp): void
    {
        $email = mb_strtolower(trim($email));
        $this->limit($this->ipLimiter, null !== $clientIp && '' !== $clientIp ? $clientIp : 'unknown');
        $this->limit($this->addressLimiter, hash('sha256', $email));

        $user = '' !== $email && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $this->users->findOtherByNormalizedEmail($email, 0) : null;
        if (!$user instanceof User || !$user->canSignIn() || $user->isManagedExternally()) {
            return;
        }

        $secret = $this->tokens->createHashedToken($user, self::TOKEN_TYPE, self::TTL_SECONDS);
        $this->mailer->send($user->getMail(), $secret, $user->getLocale());
        $this->logger->info('Sign-in link sent', ['user_id' => $user->getId()]);
    }

    /**
     * The account the link signs into, or null when the link is unknown,
     * expired or already used.
     */
    public function consume(string $secret): ?User
    {
        $token = $this->tokens->findValidHashedToken(trim($secret), self::TOKEN_TYPE);
        if (null === $token || !$this->tokens->claimUnused($token)) {
            return null;
        }

        $user = $token->getUser();
        if (!$user->canSignIn()) {
            return null;
        }
        if (!$user->isEmailVerified()) {
            $user->setEmailVerified(true);
            $this->em->flush();
        }
        $this->logger->info('Signed in with an email link', ['user_id' => $user->getId()]);

        return $user;
    }

    private function limit(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new EmailLinkRateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
