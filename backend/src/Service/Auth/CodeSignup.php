<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\SignupCode;
use App\Entity\User;
use App\Message\SendCodeSignupMessage;
use App\Repository\SignupCodeRepository;
use App\Repository\UserRepository;
use App\Service\Iam\UserLifecycleService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * An invitation code without a password (PF5): email and code, then a link.
 * The account is created when the link is opened, at the code's tier, and
 * nothing exists before that. The link works once, for 15 minutes.
 *
 * The request is queued for every valid code, so the answer takes the same
 * time whether or not the address already has an account. An address that
 * already has one is signed in by the link; the code is not spent.
 */
final readonly class CodeSignup
{
    public const TTL_SECONDS = 900;
    private const CACHE_PREFIX = 'code_signup_';

    public function __construct(
        private SignupCodeRepository $codes,
        private UserRepository $users,
        private UserLifecycleService $lifecycle,
        private SignupAdmission $admission,
        private CodeSignupMailer $mailer,
        private CacheItemPoolInterface $cache,
        private MessageBusInterface $bus,
        private LockFactory $lockFactory,
        #[Autowire(service: 'limiter.code_signup_ip')]
        private RateLimiterFactoryInterface $ipLimiter,
        #[Autowire(service: 'limiter.code_signup_address')]
        private RateLimiterFactoryInterface $addressLimiter,
    ) {
    }

    /**
     * @throws CodeSignupRateLimited
     */
    public function request(string $email, string $code, ?string $clientIp, string $locale): bool
    {
        $email = mb_strtolower(trim($email));
        $this->limit($this->ipLimiter, null !== $clientIp && '' !== $clientIp ? $clientIp : 'unknown');
        if (false !== filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $this->limit($this->addressLimiter, hash('sha256', $email));
        }

        $redeemable = $this->codes->findRedeemable($code);
        if (!$redeemable instanceof SignupCode || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $this->bus->dispatch(new SendCodeSignupMessage($email, (int) $redeemable->getId(), $locale));

        return true;
    }

    /**
     * The worker's half: store the one-time secret and mail the link.
     */
    public function deliver(string $email, int $codeId, string $locale): void
    {
        $code = $this->codes->find($codeId);
        if (!$code instanceof SignupCode || !$code->isRedeemable()) {
            return;
        }

        $secret = bin2hex(random_bytes(32));
        $item = $this->cache->getItem(self::CACHE_PREFIX.hash('sha256', $secret));
        $item->set(['email' => $email, 'codeId' => $codeId])->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
        $this->mailer->send($email, $secret, $locale);
    }

    /**
     * The link opens the account, or signs in the one this address already
     * has. Null when the link is unknown or already used.
     */
    public function consume(string $secret): ?CodeSignupOutcome
    {
        $key = self::CACHE_PREFIX.hash('sha256', trim($secret));
        $lock = $this->lockFactory->createLock($key, 30);
        if (!$lock->acquire(true)) {
            return null;
        }
        try {
            return $this->open($key);
        } finally {
            $lock->release();
        }
    }

    private function open(string $key): ?CodeSignupOutcome
    {
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $this->cache->deleteItem($key);
        $data = $item->get();
        if (!is_array($data) || !is_string($data['email'] ?? null)) {
            return null;
        }
        $codeId = $data['codeId'] ?? null;
        if (!is_int($codeId)) {
            return null;
        }

        $existing = $this->users->findOtherByNormalizedEmail($data['email'], 0);
        if ($existing instanceof User) {
            if (!$existing->canSignIn()) {
                return null;
            }
            // A local cookie would skip the identity provider's own checks.
            if ($existing->isManagedExternally()) {
                throw new CodeSignupExternallyManaged();
            }
            $this->lifecycle->markEmailVerified($existing);

            return new CodeSignupOutcome($existing, false);
        }

        $code = $this->codes->find($codeId);
        if (!$code instanceof SignupCode) {
            return null;
        }

        try {
            // Unverified first, then the false-to-true change, so the local
            // account receives the same default plugins as any other signup.
            $user = $this->lifecycle->createUser($data['email'], null, 'local', 'WEB', $code->getLevel(), false);
            $this->lifecycle->markEmailVerified($user);
            $this->admission->commitCode($user, $code);
        } catch (SignupBlockedException|\InvalidArgumentException|UniqueConstraintViolationException) {
            return null;
        }

        return new CodeSignupOutcome($user, true);
    }

    private function limit(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new CodeSignupRateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
