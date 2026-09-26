<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;

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
    ) {
    }

    /**
     * Set a title when the chat still has a placeholder. Returns the stored
     * title, or null when the chat was left unchanged.
     */
    public function nameIfUntitled(int $ownerId, ?int $chatId, string $skill, string $prompt): ?string
    {
        if (null === $chatId || $chatId <= 0) {
            return null;
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $ownerId) {
            return null;
        }

        $current = trim((string) $chat->getTitle());
        if (!\in_array($current, self::PLACEHOLDERS, true)) {
            return null;
        }

        $title = self::build($skill, $prompt);
        $chat->setTitle($title);
        $this->em->flush();

        return $title;
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
