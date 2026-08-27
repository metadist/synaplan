<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Entity\User;
use App\Repository\MessageDigestRepository;
use Psr\Log\LoggerInterface;

/**
 * Resolve `[Message:ID]` reference tags for plain-text channels.
 *
 * The web UI renders `[Message:ID]` as a clickable badge; WhatsApp/email
 * cannot, so this replaces each tag with the digest title of the referenced
 * message in quotes — the analog of `UserMemoryService::resolveMemoryTags()`.
 * Unknown or foreign-user ids resolve to an empty string, so an invented id
 * can never leak content.
 */
final readonly class MessageReferenceResolver
{
    private const TAG_PATTERN = '/\[Message\s*:\s*(\d+)\.{0,3}\]/i';

    public function __construct(
        private MessageDigestRepository $digestRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function resolveMessageTags(string $text, User $user): string
    {
        if (false === stripos($text, '[message')) {
            return $text;
        }

        preg_match_all(self::TAG_PATTERN, $text, $allMatches);
        $uniqueIds = array_unique(array_map(intval(...), $allMatches[1]));

        /** @var array<int, string> $resolved */
        $resolved = [];
        foreach ($uniqueIds as $messageId) {
            $digest = $this->digestRepository->findOneByUserAndMessage($user->getId(), $messageId);
            if (null !== $digest && $digest->isActive()) {
                $resolved[$messageId] = sprintf('("%s")', $digest->getTitle());
            } else {
                $this->logger->debug('Message reference tag could not be resolved, stripping', [
                    'user_id' => $user->getId(),
                    'message_id' => $messageId,
                ]);
                $resolved[$messageId] = '';
            }
        }

        return (string) preg_replace_callback(
            self::TAG_PATTERN,
            static fn (array $matches): string => $resolved[(int) $matches[1]] ?? '',
            $text
        );
    }
}
