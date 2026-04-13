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

    public function __construct(
        private EntityManagerInterface $em,
        private GuestSessionRepository $sessionRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createSession(string $sessionId, Request $request): GuestSession
    {
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
     * Resolve the admin user used as processing context for guest messages.
     * Mirrors the widget pattern where chats run under the widget owner.
     */
    public function getProcessingUser(): ?User
    {
        $admin = $this->userRepository->findOneBy(['userLevel' => 'ADMIN']);
        if (!$admin) {
            $this->logger->error('No admin user found for guest processing context');
        }

        return $admin;
    }

    public function attachChat(GuestSession $session, int $chatId): void
    {
        if ($session->getChatId() !== $chatId) {
            $session->setChatId($chatId);
            $this->em->flush();
        }
    }
}
