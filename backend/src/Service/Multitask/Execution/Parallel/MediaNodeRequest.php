<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

/**
 * A self-contained description of a single media-generation node, enough to run
 * it in an isolated subprocess.
 *
 * `messageId` lets the subprocess reload the REAL inbound message (with its
 * file attachments) so reference-image flows (pic2pic image edit) behave
 * exactly like the inline path. `thread` and `options` travel along for the
 * same reason: the handler uses the conversation for prompt extraction and
 * honours processing options. The thread is pre-normalized to plain
 * `{role, content}` arrays — Doctrine entities cannot cross the process
 * boundary.
 */
final readonly class MediaNodeRequest
{
    /**
     * @param array<string, mixed>                       $params
     * @param list<array{role: string, content: string}> $thread
     * @param array<string, mixed>                       $options
     */
    public function __construct(
        public string $nodeId,
        public string $capability,
        public string $prompt,
        public ?int $userId,
        public string $language,
        public array $params = [],
        public ?int $messageId = null,
        public array $thread = [],
        public array $options = [],
    ) {
    }
}
