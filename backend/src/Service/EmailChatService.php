<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Chat;
use App\Repository\UserRepository;
use App\Repository\ChatRepository;
use App\Repository\EmailBlacklistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Chat Service
 * 
 * Handles email-based chat system:
 * - smart@synaplan.com (general chat)
 * - smart+keyword@synaplan.com (specific chat context)
 * 
 * This is a TOOL that allows users to chat via email.
 */
class EmailChatService
{
    private const BASE_EMAIL = 'smart@synaplan.com';
    private const MAX_ANONYMOUS_EMAILS_PER_HOUR = 10;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private ChatRepository $chatRepository,
        private EmailBlacklistRepository $blacklistRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Parse email address to extract keyword
     * smart@synaplan.com -> null
     * smart+keyword@synaplan.com -> 'keyword'
     */
    public function parseEmailKeyword(string $toEmail): ?string
    {
        $toEmail = strtolower(trim($toEmail));
        
        // Check if email matches pattern: smart+keyword@synaplan.com
        if (preg_match('/^smart\+([a-z0-9\-_]+)@synaplan\.com$/i', $toEmail, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Find or create user from email address
     * Returns registered user or creates anonymous user
     */
    public function findOrCreateUserFromEmail(string $fromEmail): array
    {
        $fromEmail = strtolower(trim($fromEmail));

        // Check blacklist first
        if ($this->blacklistRepository->isBlacklisted($fromEmail)) {
            return [
                'user' => null,
                'blacklisted' => true,
                'error' => 'Email address is blacklisted'
            ];
        }

        // Try to find registered user by email
        $user = $this->userRepository->findOneBy(['mail' => $fromEmail]);

        if ($user) {
            return [
                'user' => $user,
                'is_anonymous' => false,
                'blacklisted' => false
            ];
        }

        // Check if anonymous user with this email exists
        $userDetails = $this->userRepository->createQueryBuilder('u')
            ->where('JSON_EXTRACT(u.userDetails, \'$.anonymous_email\') = :email')
            ->setParameter('email', $fromEmail)
            ->getQuery()
            ->getOneOrNullResult();

        if ($userDetails) {
            return [
                'user' => $userDetails,
                'is_anonymous' => true,
                'blacklisted' => false
            ];
        }

        // Check spam protection for new anonymous users
        if ($this->isSpamming($fromEmail)) {
            $this->blacklistRepository->addToBlacklist(
                $fromEmail,
                'Automatic: Too many emails in short time',
                null
            );

            return [
                'user' => null,
                'blacklisted' => true,
                'error' => 'Too many requests. Email has been blacklisted.'
            ];
        }

        // Create new anonymous user
        $anonymousUser = new User();
        $anonymousUser->setMail('anonymous_' . bin2hex(random_bytes(8)) . '@synaplan.local');
        $anonymousUser->setPw(''); // No password for anonymous
        $anonymousUser->setInType('EMAIL');
        $anonymousUser->setUserLevel('NEW'); // Will become ANONYMOUS if phone not verified
        
        $details = [
            'anonymous_email' => $fromEmail,
            'firstName' => 'Email User',
            'lastName' => '',
            'created_via' => 'email',
            'original_email' => $fromEmail
        ];
        $anonymousUser->setUserDetails($details);

        $this->em->persist($anonymousUser);
        $this->em->flush();

        $this->logger->info('Created anonymous user from email', [
            'email' => $fromEmail,
            'user_id' => $anonymousUser->getId()
        ]);

        return [
            'user' => $anonymousUser,
            'is_anonymous' => true,
            'blacklisted' => false,
            'created' => true
        ];
    }

    /**
     * Find or create chat context for email thread
     * 
     * @param User $user
     * @param string|null $keyword From smart+keyword@synaplan.com
     * @param string|null $emailSubject Email subject (for thread detection)
     * @param string|null $inReplyTo In-Reply-To header (for threading)
     * @return Chat
     */
    public function findOrCreateChatContext(
        User $user,
        ?string $keyword,
        ?string $emailSubject,
        ?string $inReplyTo
    ): Chat {
        // If keyword is provided, use it as chat identifier
        if ($keyword) {
            $chat = $this->chatRepository->findOneBy([
                'userId' => $user->getId(),
                'title' => 'Email: ' . $keyword
            ]);

            if (!$chat) {
                $chat = new Chat();
                $chat->setUserId($user->getId());
                $chat->setTitle('Email: ' . $keyword);

                $this->em->persist($chat);
                $this->em->flush();

                $this->logger->info('Created new email chat context', [
                    'user_id' => $user->getId(),
                    'keyword' => $keyword,
                    'chat_id' => $chat->getId()
                ]);
            }

            return $chat;
        }

        // Try to find chat by In-Reply-To header (email threading)
        if ($inReplyTo) {
            $chats = $this->chatRepository->findBy(['userId' => $user->getId()]);
            foreach ($chats as $chat) {
                $chatData = $chat->getChatData();
                if (isset($chatData['email_message_id']) && $chatData['email_message_id'] === $inReplyTo) {
                    return $chat;
                }
            }
        }

        // No specific context - create/use general email chat
        $chat = $this->chatRepository->findOneBy([
            'userId' => $user->getId(),
            'title' => 'Email Conversation'
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
     * Check if email address is spamming
     */
    private function isSpamming(string $email): bool
    {
        $oneHourAgo = time() - 3600;
        $createdAfter = date('YmdHis', $oneHourAgo);

        // Count anonymous users created from this email in last hour
        $count = $this->userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('JSON_EXTRACT(u.userDetails, \'$.anonymous_email\') = :email')
            ->andWhere('u.created >= :created_after')
            ->setParameter('email', $email)
            ->setParameter('created_after', $createdAfter)
            ->getQuery()
            ->getSingleScalarResult();

        return $count >= self::MAX_ANONYMOUS_EMAILS_PER_HOUR;
    }

    /**
     * Get user's email keyword (for smart+keyword@synaplan.com)
     */
    public function getUserEmailKeyword(User $user): ?string
    {
        $details = $user->getUserDetails();
        return $details['email_keyword'] ?? null;
    }

    /**
     * Set user's email keyword
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
            'keyword' => $keyword
        ]);
    }

    /**
     * Get user's personal email address
     */
    public function getUserPersonalEmailAddress(User $user): string
    {
        $keyword = $this->getUserEmailKeyword($user);
        
        if ($keyword) {
            return "smart+{$keyword}@synaplan.com";
        }

        return self::BASE_EMAIL;
    }
}

