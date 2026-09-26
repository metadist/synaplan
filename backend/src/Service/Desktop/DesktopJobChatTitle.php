<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Names a still-untitled chat from the skill and the instruction, so the
 * completion note is not what the history list shows.
 */
final readonly class DesktopJobChatTitle
{
    public const MAX_LENGTH = 60;

    /**
     * Titles the web app treats as "no title yet", in every shipped locale.
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = [
        '',
        'New Chat',
        'Neuer Chat',
        'Nuevo Chat',
        'Nouveau chat',
        'Yeni Sohbet',
    ];

    public function __construct(
        private ChatRepository $chatRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Set a title when the chat still has a placeholder. Returns the stored
     * title, or null when the chat was left unchanged.
     *
     * Never throws. The job is already queued when this runs, so a title
     * write that fails must not turn a successful enqueue into an error.
     */
    public function nameIfUntitled(int $ownerId, ?int $chatId, string $skill, string $prompt): ?string
    {
        try {
            return $this->apply($ownerId, $chatId, $skill, $prompt);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to name the chat for a desktop job', [
                'chat_id' => $chatId,
                'owner_id' => $ownerId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function apply(int $ownerId, ?int $chatId, string $skill, string $prompt): ?string
    {
        if (null === $chatId || $chatId <= 0) {
            return null;
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $ownerId) {
            return null;
        }

        if (!self::isPlaceholder($chat->getTitle())) {
            return null;
        }

        $previous = $chat->getTitle();
        $title = self::build($skill, $prompt);
        $chat->setTitle($title);
        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $chat->setTitle($previous);
            throw $e;
        }

        return $title;
    }

    /**
     * Same rule as the history list: the shipped "new chat" labels, plus the
     * legacy `Chat 12` form (`isDefaultChatTitle` treats a `Chat ` prefix as
     * untitled).
     */
    public static function isPlaceholder(?string $title): bool
    {
        $current = trim((string) $title);
        if (\in_array($current, self::PLACEHOLDERS, true)) {
            return true;
        }

        return str_starts_with($current, 'Chat ');
    }

    public static function build(string $skill, string $prompt): string
    {
        $skill = trim($skill);
        $prompt = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        $title = '' === $prompt ? $skill : $skill.': '.$prompt;
        if (mb_strlen($title) <= self::MAX_LENGTH) {
            return $title;
        }

        return mb_substr($title, 0, self::MAX_LENGTH - 1).'…';
    }
}
