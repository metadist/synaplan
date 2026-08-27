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

    /**
     * Upper bound on distinct ids looked up per response, mirroring the web
     * resolve endpoint. Retrieval offers the model at most `DIGEST.TOP_K`
     * references, so anything beyond this is a model gone haywire — and must
     * not turn one reply into an unbounded database lookup.
     */
    private const MAX_IDS_PER_RESPONSE = 100;

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
        $uniqueIds = array_values(array_unique(array_map(intval(...), $allMatches[1])));
        if ([] === $uniqueIds) {
            return $text;
        }

        if (count($uniqueIds) > self::MAX_IDS_PER_RESPONSE) {
            $this->logger->warning('Response carries more message reference tags than we resolve, extra tags are stripped', [
                'user_id' => $user->getId(),
                'tag_count' => count($uniqueIds),
                'limit' => self::MAX_IDS_PER_RESPONSE,
            ]);
            $uniqueIds = array_slice($uniqueIds, 0, self::MAX_IDS_PER_RESPONSE);
        }

        // One query for the whole response — the digests are already scoped to
        // the user, so an invented or foreign id simply has no row and its tag
        // is stripped below.
        $digests = $this->digestRepository->findActiveByUserAndMessageIds($user->getId(), $uniqueIds);

        /** @var array<int, string> $resolved */
        $resolved = [];
        foreach ($digests as $digest) {
            $resolved[$digest->getMessageId()] = sprintf('("%s")', $digest->getTitle());
        }

        foreach ($uniqueIds as $messageId) {
            if (!isset($resolved[$messageId])) {
                $this->logger->debug('Message reference tag could not be resolved, stripping', [
                    'user_id' => $user->getId(),
                    'message_id' => $messageId,
                ]);
            }
        }

        return (string) preg_replace_callback(
            self::TAG_PATTERN,
            static fn (array $matches): string => $resolved[(int) $matches[1]] ?? '',
            $text
        );
    }
}
