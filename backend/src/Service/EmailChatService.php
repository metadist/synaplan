<?php

namespace App\Service;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Email\SmartEmailHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Chat Service.
 *
 * Handles email-based chat system:
 * - smart@synaplan.net (general chat)
 * - smart+keyword@synaplan.net (specific chat context)
 *
 * This is a TOOL that allows users to chat via email.
 */
class EmailChatService
{
    private const MAX_ANONYMOUS_EMAILS_PER_HOUR = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private ChatRepository $chatRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Parse email address to extract keyword
     * smart@synaplan.net -> null
     * smart+keyword@synaplan.net -> 'keyword'.
     */
    public function parseEmailKeyword(string $toEmail): ?string
    {
        return SmartEmailHelper::extractKeyword($toEmail);
    }

    /**
     * Find or create user from email address
     * Returns registered user or creates anonymous user.
     */
    public function findOrCreateUserFromEmail(string $fromEmail): array
    {
        $fromEmail = strtolower(trim($fromEmail));

        // Try to find registered user by email
        $user = $this->userRepository->findOneBy(['mail' => $fromEmail]);

        if ($user) {
            return [
                'user' => $user,
                'is_anonymous' => false,
            ];
        }

        // Check if anonymous user with this email exists
        // Use native SQL query because Doctrine DQL doesn't support JSON_EXTRACT
        $sql = "SELECT BID FROM BUSER WHERE JSON_EXTRACT(BUSERDETAILS, '$.anonymous_email') = :email LIMIT 1";
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue('email', $fromEmail);
        $result = $stmt->executeQuery();
        $userId = $result->fetchOne();

        if ($userId) {
            $userDetails = $this->userRepository->find($userId);
            if ($userDetails) {
                return [
                    'user' => $userDetails,
                    'is_anonymous' => true,
                ];
            }
        }

        // Check spam protection via usage tracking
        if ($this->isSpamming($fromEmail)) {
            return [
                'user' => null,
                'is_anonymous' => true,
                'error' => 'Too many requests. Please try again later.',
            ];
        }

        // Create new anonymous user
        $anonymousUser = new User();
        $anonymousUser->setMail('anonymous_'.bin2hex(random_bytes(8)).'@synaplan.local');
        $anonymousUser->setPw(null); // No password for anonymous users
        $anonymousUser->setType('MAIL'); // EMAIL-based anonymous user
        $anonymousUser->setProviderId('email'); // Identify as email-based
        $anonymousUser->setUserLevel('ANONYMOUS'); // Anonymous users get ANONYMOUS rate limits

        $details = [
            'anonymous_email' => $fromEmail,
            'firstName' => 'Email User',
            'lastName' => '',
            'created_via' => 'email',
            'original_email' => $fromEmail,
        ];
        $anonymousUser->setUserDetails($details);

        $this->em->persist($anonymousUser);
        $this->em->flush();

        $this->logger->info('Created anonymous user from email', [
            'email' => $fromEmail,
            'user_id' => $anonymousUser->getId(),
        ]);

        return [
            'user' => $anonymousUser,
            'is_anonymous' => true,
            'created' => true,
        ];
    }

    /**
     * Find or create user from WhatsApp phone number.
     * Priority: Verified phone > Anonymous phone > Create new.
     */
    public function findOrCreateUserFromPhone(string $phoneNumber): array
    {
        $phoneNumber = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // PRIORITY 1: Check if user with verified phone exists
        // Format: {"phone_number": "491234567", "phone_verified_at": 1234567890}
        $sql = "SELECT BID FROM BUSER
                WHERE JSON_EXTRACT(BUSERDETAILS, '$.phone_number') = :phone
                AND JSON_EXTRACT(BUSERDETAILS, '$.phone_verified_at') IS NOT NULL
                ORDER BY CAST(JSON_EXTRACT(BUSERDETAILS, '$.phone_verified_at') AS UNSIGNED) DESC
                LIMIT 1";
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue('phone', $phoneNumber);
        $result = $stmt->executeQuery();
        $userId = $result->fetchOne();

        if ($userId) {
            $user = $this->userRepository->find($userId);
            if ($user) {
                $this->logger->info('Found verified phone user for WhatsApp', [
                    'phone' => $phoneNumber,
                    'user_id' => $user->getId(),
                    'level' => $user->getUserLevel(),
                ]);

                return [
                    'user' => $user,
                    'is_anonymous' => false,
                ];
            }
        }

        // Check if anonymous user with this phone exists
        $sql = "SELECT BID FROM BUSER WHERE JSON_EXTRACT(BUSERDETAILS, '$.anonymous_phone') = :phone LIMIT 1";
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue('phone', $phoneNumber);
        $result = $stmt->executeQuery();
        $userId = $result->fetchOne();

        if ($userId) {
            $user = $this->userRepository->find($userId);
            if ($user) {
                return [
                    'user' => $user,
                    'is_anonymous' => true,
                ];
            }
        }

        // Check spam protection via usage tracking
        if ($this->isSpamming($phoneNumber)) {
            return [
                'user' => null,
                'is_anonymous' => true,
                'error' => 'Too many requests. Please try again later.',
            ];
        }

        // Create new anonymous WhatsApp user
        $anonymousUser = new User();
        $anonymousUser->setMail('whatsapp_'.bin2hex(random_bytes(8)).'@synaplan.local');
        $anonymousUser->setPw(null); // No password for anonymous users
        $anonymousUser->setType('WHATSAPP'); // WhatsApp-based anonymous user
        $anonymousUser->setProviderId('whatsapp'); // Identify as WhatsApp-based
        $anonymousUser->setUserLevel('ANONYMOUS'); // Anonymous users get ANONYMOUS rate limits

        $details = [
            'anonymous_phone' => $phoneNumber,
            'firstName' => 'WhatsApp User',
            'lastName' => '',
            'created_via' => 'whatsapp',
            'original_phone' => $phoneNumber,
        ];
        $anonymousUser->setUserDetails($details);

        $this->em->persist($anonymousUser);
        $this->em->flush();

        $this->logger->info('Created anonymous user from WhatsApp', [
            'phone' => $phoneNumber,
            'user_id' => $anonymousUser->getId(),
        ]);

        return [
            'user' => $anonymousUser,
            'is_anonymous' => true,
            'created' => true,
        ];
    }

    /**
     * Find or create chat context for email thread.
     *
     * @param string|null $keyword      From smart+keyword@synaplan.net
     * @param string|null $emailSubject Email subject (for thread detection)
     * @param string|null $inReplyTo    In-Reply-To header (for threading)
     */
    public function findOrCreateChatContext(
        User $user,
        ?string $keyword,
        ?string $emailSubject,
        ?string $inReplyTo,
    ): Chat {
        // If keyword is provided, use it as chat identifier
        if ($keyword) {
            $chat = $this->chatRepository->findOneBy([
                'userId' => $user->getId(),
                'title' => 'Email: '.$keyword,
            ]);

            if (!$chat) {
                $chat = new Chat();
                $chat->setUserId($user->getId());
                $chat->setTitle('Email: '.$keyword);

                $this->em->persist($chat);
                $this->em->flush();

                $this->logger->info('Created new email chat context', [
                    'user_id' => $user->getId(),
                    'keyword' => $keyword,
                    'chat_id' => $chat->getId(),
                ]);
            }

            return $chat;
        }

        // No specific context - create/use general email chat
        $chat = $this->chatRepository->findOneBy([
            'userId' => $user->getId(),
            'title' => 'Email Conversation',
        ]);

        if (!$chat) {
            $chat = new Chat();
            $chat->setUserId($user->getId());
            $chat->setTitle('Email Conversation');

            $this->em->persist($chat);
            $this->em->flush();
        }

        return $chat;
    }

    /**
     * Check if email address is spamming.
     */
    private function isSpamming(string $email): bool
    {
        $oneHourAgo = time() - 3600;
        $createdAfter = date('YmdHis', $oneHourAgo);

        // Count anonymous users created from this email in last hour
        // Use native SQL query because Doctrine DQL doesn't support JSON_EXTRACT
        $sql = "SELECT COUNT(BID) FROM BUSER WHERE JSON_EXTRACT(BUSERDETAILS, '$.anonymous_email') = :email AND BCREATED >= :created_after";
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue('email', $email);
        $stmt->bindValue('created_after', $createdAfter);
        $result = $stmt->executeQuery();
        $count = $result->fetchOne();

        return $count >= self::MAX_ANONYMOUS_EMAILS_PER_HOUR;
    }

    /**
     * Get user's email keyword (for smart+keyword@synaplan.net).
     */
    public function getUserEmailKeyword(User $user): ?string
    {
        $details = $user->getUserDetails();

        return $details['email_keyword'] ?? null;
    }

    /**
     * Set user's email keyword.
     */
    public function setUserEmailKeyword(User $user, string $keyword): void
    {
        $keyword = preg_replace('/[^a-z0-9\-_]/', '', strtolower($keyword));

        if (empty($keyword)) {
            throw new \InvalidArgumentException('Invalid keyword format');
        }

        $details = $user->getUserDetails();
        $details['email_keyword'] = $keyword;
        $user->setUserDetails($details);

        $this->em->flush();

        $this->logger->info('Set user email keyword', [
            'user_id' => $user->getId(),
            'keyword' => $keyword,
        ]);
    }

    /**
     * Get user's personal email address.
     */
    public function getUserPersonalEmailAddress(User $user): string
    {
        return SmartEmailHelper::buildAddress($this->getUserEmailKeyword($user));
    }
}
