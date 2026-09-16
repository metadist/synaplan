<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class GuestSessionService
{
    public const DEFAULT_MAX_MESSAGES = 5;
    public const SESSION_EXPIRY_HOURS = 24;
    public const MAX_SESSIONS_PER_IP = 5;

    /**
     * Hard cap on guest messages per IP across all active sessions.
     *
     * Prevents incognito-window resets from granting a fresh quota: the
     * counter is aggregated over every non-expired session from the same IP.
     * Aligns with DEFAULT_MAX_MESSAGES so a single guest user gets the same
     * effective trial whether they reuse one session or spread requests
     * across many.
     */
    public const MAX_MESSAGES_PER_IP = 5;

    /**
     * Dedicated processing identity for browser guest chat (issue #1879).
     * Must stay `ANONYMOUS` so `RATELIMITS_ANONYMOUS` and BUSELOG attribution
     * apply instead of the lowest-id ADMIN account.
     */
    public const PROCESSING_USER_EMAIL = 'guest-processor@synaplan.internal';

    public static function isReservedProcessorEmail(string $email): bool
    {
        return 0 === strcasecmp(trim($email), self::PROCESSING_USER_EMAIL);
    }

    /**
     * True only for the runtime-created guest processor, not a human who
     * happened to register the reserved address.
     */
    public static function isSystemProcessor(User $user): bool
    {
        if (!self::isReservedProcessorEmail($user->getMail())) {
            return false;
        }
        if ('guest' !== $user->getProviderId()) {
            return false;
        }
        $details = $user->getUserDetails();

        return true === ($details['system'] ?? false);
    }

    private ?User $cachedProcessingUser = null;

    /**
     * @param int $maxSessionsPerIp anti-abuse cap on concurrent active guest
     *                              sessions per IP. Defaults to
     *                              self::MAX_SESSIONS_PER_IP in production; the
     *                              E2E suite raises it via GUEST_MAX_SESSIONS_PER_IP
     *                              because every test hits the server from the
     *                              same client IP and would otherwise 429.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private GuestSessionRepository $sessionRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private GuestChatConfig $guestChatConfig,
        private int $maxSessionsPerIp = self::MAX_SESSIONS_PER_IP,
    ) {
    }

    public function resolveClientIp(Request $request): ?string
    {
        return $request->headers->get('CF-Connecting-IP') ?? $request->getClientIp();
    }

    /**
     * Create a new guest session with the given (server-generated) session ID.
     *
     * The new session's `maxMessages` is capped to the IP's remaining budget
     * (MAX_MESSAGES_PER_IP minus messages already spent across other active
     * sessions). When the budget is exhausted, the session is created with
     * `maxMessages = 0` so the client immediately sees `limitReached = true`.
     *
     * @throws \OverflowException when the IP has exceeded its concurrent session limit
     * @throws \LogicException    when the sessionId already exists (should never happen with server UUIDs)
     */
    public function createSession(string $sessionId, Request $request): GuestSession
    {
        $existing = $this->sessionRepository->findBySessionId($sessionId);
        if ($existing) {
            throw new \LogicException(sprintf('Session ID already exists: %s', substr($sessionId, 0, 12)));
        }

        $ip = $this->resolveClientIp($request);

        if ($ip) {
            $activeCount = $this->sessionRepository->countActiveSessionsByIp($ip);
            if ($activeCount >= $this->maxSessionsPerIp) {
                $this->logger->warning('Guest session IP rate limit exceeded', [
                    'ip' => $ip,
                    'active_sessions' => $activeCount,
                ]);

                throw new \OverflowException('Too many guest sessions from this IP');
            }
        }

        $session = new GuestSession();
        $session->setSessionId($sessionId);
        $session->setIpAddress($ip);

        $maxMessages = self::DEFAULT_MAX_MESSAGES;
        if ($ip) {
            $alreadySpent = $this->sessionRepository->sumActiveMessageCountByIp($ip);
            $ipRemaining = max(0, self::MAX_MESSAGES_PER_IP - $alreadySpent);
            $maxMessages = min(self::DEFAULT_MAX_MESSAGES, $ipRemaining);

            if (0 === $maxMessages) {
                $this->logger->info('Guest IP message budget exhausted', [
                    'ip' => $ip,
                    'already_spent' => $alreadySpent,
                ]);
            }
        }
        $session->setMaxMessages($maxMessages);

        $country = $request->headers->get('CF-IPCountry');
        $session->setCountry($country);

        $this->em->persist($session);
        $this->em->flush();

        $this->logger->info('New guest session created', [
            'session_id' => substr($sessionId, 0, 12).'...',
            'country' => $session->getCountry(),
            'max_messages' => $maxMessages,
        ]);

        return $session;
    }

    public function getSession(string $sessionId): ?GuestSession
    {
        // GUEST_CHAT_ENABLED=false (issue #1517): resolve no session for ANY
        // consumer - guest turns also flow through StreamController's
        // guestSession parameter, not only through GuestChatController's own
        // (separately gated) endpoints. A stale stored session must not keep
        // consuming AI after the operator turns the trial off.
        if (!$this->guestChatConfig->isEnabled()) {
            return null;
        }

        return $this->sessionRepository->findBySessionId($sessionId);
    }

    public function checkLimit(GuestSession $session): bool
    {
        return $this->getRemainingMessages($session) > 0;
    }

    public function isLimitReached(GuestSession $session): bool
    {
        return 0 === $this->getRemainingMessages($session);
    }

    public function incrementCount(GuestSession $session): void
    {
        $session->incrementMessageCount();
        $this->em->flush();
    }

    /**
     * Compute the messages a guest session may still send.
     *
     * Combines the session's own remaining budget with the per-IP cap so
     * spinning up new incognito sessions cannot bypass the trial quota:
     * `min(session_remaining, MAX_MESSAGES_PER_IP - sum(active sessions on same IP))`.
     */
    public function getRemainingMessages(GuestSession $session): int
    {
        $sessionRemaining = $session->getRemainingMessages();

        $ip = $session->getIpAddress();
        if (!$ip) {
            return $sessionRemaining;
        }

        $aggregated = $this->sessionRepository->sumActiveMessageCountByIp($ip, $session->getId());
        $ipRemaining = max(0, self::MAX_MESSAGES_PER_IP - $aggregated - $session->getMessageCount());

        return min($sessionRemaining, $ipRemaining);
    }

    /**
     * Resolve the system user used as processing context for guest messages.
     *
     * Issue #1879: this used to be the lowest-id ADMIN, which bypassed every
     * rate limit and booked guest token cost onto a real person's ledger.
     * A dedicated `ANONYMOUS` account is found or created at runtime so
     * existing installs pick it up on the first guest turn without a seeder.
     * The result is cached for the lifetime of the service instance.
     */
    public function getProcessingUser(): ?User
    {
        if ($this->cachedProcessingUser) {
            return $this->cachedProcessingUser;
        }

        $user = $this->userRepository->findByEmail(self::PROCESSING_USER_EMAIL);
        if ($user) {
            if (!self::isSystemProcessor($user)) {
                $this->logger->error('Guest processing email is occupied by a non-system account; refusing to mutate it', [
                    'user_id' => $user->getId(),
                    'level' => $user->getUserLevel(),
                ]);

                return null;
            }
            $this->cachedProcessingUser = $this->ensureAnonymousLevel($user);

            return $this->cachedProcessingUser;
        }

        $created = $this->createProcessingUser();
        if (null === $created) {
            return null;
        }

        $this->cachedProcessingUser = $created;

        return $created;
    }

    /**
     * Guest traffic must never inherit ADMIN (unlimited) limits.
     */
    private function ensureAnonymousLevel(User $user): User
    {
        if ('ANONYMOUS' === $user->getUserLevel()) {
            return $user;
        }

        $this->logger->warning('Guest processing user was not ANONYMOUS; correcting level', [
            'user_id' => $user->getId(),
            'previous_level' => $user->getUserLevel(),
        ]);
        $user->setUserLevel('ANONYMOUS');
        $this->em->flush();

        return $user;
    }

    private function createProcessingUser(): ?User
    {
        $user = new User();
        $user->setMail(self::PROCESSING_USER_EMAIL);
        $user->setPw(null);
        $user->setType('WEB');
        $user->setProviderId('guest');
        $user->setUserLevel('ANONYMOUS');
        $user->setCreated(date('Y-m-d\TH:i:s'));
        $user->setUserDetails([
            'firstName' => 'Guest',
            'lastName' => 'Processor',
            'created_via' => 'guest_chat',
            'system' => true,
        ]);

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $existing = $this->userRepository->findByEmail(self::PROCESSING_USER_EMAIL);
            if ($existing && self::isSystemProcessor($existing)) {
                return $this->ensureAnonymousLevel($existing);
            }

            $this->logger->error('Guest processing user create raced and could not be re-loaded');

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create guest processing user', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->logger->info('Created anonymous guest processing user', [
            'user_id' => $user->getId(),
        ]);

        return $user;
    }

    /**
     * Link a chat to the session. Does NOT flush — caller is responsible.
     */
    public function attachChat(GuestSession $session, int $chatId): void
    {
        if ($session->getChatId() !== $chatId) {
            $session->setChatId($chatId);
        }
    }
}
