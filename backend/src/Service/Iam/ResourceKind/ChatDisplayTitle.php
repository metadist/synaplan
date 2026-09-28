<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\AI\Messages\AnthropicContentText;
use App\Entity\Chat;
use App\Entity\Message;
use App\Service\PastedContentText;

/**
 * The name a shared chat shows in a list.
 *
 * Matches the owner's history: a real title, otherwise the first message,
 * otherwise the same "New Chat" placeholder the owner list uses. Never a raw id.
 */
final class ChatDisplayTitle
{
    public static function of(Chat $chat): string
    {
        $title = trim((string) $chat->getTitle());
        if ('' !== $title && !self::isPlaceholder($title)) {
            return $title;
        }

        $preview = self::firstUserPreview($chat);
        if (null !== $preview) {
            return $preview;
        }

        return 'New Chat';
    }

    private static function isPlaceholder(string $title): bool
    {
        return 'New Chat' === $title
            || 'Neuer Chat' === $title
            || str_starts_with($title, 'Chat ');
    }

    private static function firstUserPreview(Chat $chat): ?string
    {
        foreach ($chat->getMessages() as $message) {
            if (!$message instanceof Message || 'IN' !== $message->getDirection()) {
                continue;
            }
            $content = AnthropicContentText::humanText(PastedContentText::strip(
                (string) preg_replace('/^\/(?:pic|vid|audio|tts|image|video|search|help)\s*/i', '', $message->getText()),
            ));
            $content = trim($content);
            if ('' === $content) {
                continue;
            }

            return mb_strlen($content) > 30 ? mb_substr($content, 0, 30).'…' : $content;
        }

        return null;
    }
}
