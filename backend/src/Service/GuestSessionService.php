<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\GuestSessionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class GuestSessionService
{
    public const DEFAULT_MAX_MESSAGES = 5;
    public const SESSION_EXPIRY_HOURS = 24;

    private ?User $cachedProcessingUser = null;

    public function __construct(
        private EntityManagerInterface $em,
        private GuestSessionRepository $sessionRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createSession(string $sessionId, Request $request): GuestSession
    {
        $existing = $this->sessionRepository->findBySessionId($sessionId);
        if ($existing) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $session = new GuestSession();
        $session->setSessionId($sessionId);
        $session->setMaxMessages(self::DEFAULT_MAX_MESSAGES);

        $ip = $request->headers->get('CF-Connecting-IP') ?? $request->getClientIp();
        $session->setIpAddress($ip);

        $country = $request->headers->get('CF-IPCountry');
        $session->setCountry($country);

        $this->em->persist($session);
        $this->em->flush();

        $this->logger->info('New guest session created', [
            'session_id' => substr($sessionId, 0, 12).'...',
            'country' => $session->getCountry(),
        ]);

        return $session;
    }

    public function getSession(string $sessionId): ?GuestSession
    {
        return $this->sessionRepository->findBySessionId($sessionId);
    }

    public function checkLimit(GuestSession $session): bool
    {
        return !$session->isLimitReached();
    }

    public function incrementCount(GuestSession $session): void
    {
        $session->incrementMessageCount();
        $this->em->flush();
    }

    public function getRemainingMessages(GuestSession $session): int
    {
        return $session->getRemainingMessages();
    }

    /**
     * Resolve the system admin used as processing context for guest messages.
     * Uses the admin with the lowest ID for deterministic behaviour.
     * The result is cached for the lifetime of the service instance.
     */
    public function getProcessingUser(): ?User
    {
        if ($this->cachedProcessingUser) {
            return $this->cachedProcessingUser;
        }

        $admin = $this->userRepository->createQueryBuilder('u')
            ->where('u.userLevel = :level')
            ->setParameter('level', 'ADMIN')
            ->orderBy('u.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$admin) {
            $this->logger->error('No admin user found for guest processing context');

            return null;
        }

        $this->cachedProcessingUser = $admin;

        return $admin;
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
