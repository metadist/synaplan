<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Message\SendPasswordResetMessage;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Email\InternalEmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Forgot password": mail a reset link to an address.
 *
 * The public form is guarded three ways so it cannot be used to flood
 * someone's mailbox: the human check (in the controller), a window per
 * client IP and a window per address, keyed by its hash. The address
 * window counts every request for it, whether or not it has an account,
 * so a 429 reveals nothing about accounts either.
 *
 * The request only queues {@see SendPasswordResetMessage}; the lookup,
 * token and mail run in the worker, so the answer takes the same time for
 * a known and an unknown address.
 */
final readonly class PasswordResetRequest
{
    public const TOKEN_TYPE = 'password_reset';
    public const TTL_SECONDS = 3600;

    public function __construct(
        private UserRepository $users,
        private VerificationTokenRepository $tokens,
        private InternalEmailService $mailer,
        #[Autowire(service: 'limiter.password_reset_ip')]
        private RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.password_reset_address')]
        private RateLimiterFactoryInterface $addressLimiter,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PasswordResetRateLimited
     */
    public function request(string $email, ?string $clientIp): void
    {
        $email = mb_strtolower(trim($email));
        $this->limit($this->ipLimiter, null !== $clientIp && '' !== $clientIp ? $clientIp : 'unknown');
        $this->limit($this->addressLimiter, hash('sha256', $email));

        if (false !== filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->bus->dispatch(new SendPasswordResetMessage($email));
        }
    }

    /**
     * The worker's half: mail the link when the address has an account
     * that signs in with a password here; otherwise do nothing.
     */
    public function deliver(string $email): void
    {
        $user = $this->users->findOtherByNormalizedEmail($email, 0);
        if (!$user instanceof User || !$user->canSignIn() || $user->isManagedExternally()) {
            return;
        }

        $token = $this->tokens->createToken($user, self::TOKEN_TYPE, self::TTL_SECONDS);
        try {
            $this->mailer->sendPasswordResetEmail($user->getMail(), $token->getToken(), $user->getLocale());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send password reset email', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);

            return;
        }

        $this->logger->info('Password reset requested', ['user_id' => $user->getId()]);
    }

    private function limit(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new PasswordResetRateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
