<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\User;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\UserMemoryService;

/**
 * Resolves the reference tags an AI reply may carry before that reply leaves
 * the web chat. Memory and message tags become readable text. Doc tags become
 * Markdown links. Channels that also show the reply in the app keep the
 * `[Doc:slug]` token in the stored text and only link it on the way out.
 */
final readonly class ExternalReplyReferences
{
    public function __construct(
        private UserMemoryService $memories,
        private MessageReferenceResolver $messages,
        private PlatformDocReferenceResolver $docs,
    ) {
    }

    public function resolve(string $text, User $user): string
    {
        return $this->resolveDocTags($this->resolveStored($text, $user));
    }

    /**
     * Memory and message tags only. `[Doc:slug]` stays so the web chat can
     * render it as a pill from the stored docs list.
     */
    public function resolveStored(string $text, User $user): string
    {
        return $this->messages->resolveMessageTags(
            $this->memories->resolveMemoryTags($text, $user),
            $user,
        );
    }

    public function resolveDocTags(string $text): string
    {
        return $this->docs->resolveDocTags($text);
    }
}
