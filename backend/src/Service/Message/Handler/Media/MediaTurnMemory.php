<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Memory\PendingMemoryExtraction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queues the memory job for the user's prompt of a media turn, gated like a
 * chat turn: incognito, a widget or `disable_memories`, the user's own
 * setting, the PERF.V2_PIPELINE switch. The asset is the answer, so the
 * extractor gets an empty answer and reads the user turns only.
 */
final readonly class MediaTurnMemory
{
    public function __construct(
        private PendingMemoryExtraction $pendingMemoryExtraction,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     */
    public function dispatch(Message $message, array $thread, array $classification, array $options): void
    {
        if ('' === trim($message->getText())) {
            return;
        }

        $incognito = !empty($options['incognito']);
        $disabledByRequest = !empty($options['disable_memories'])
            || ('WIDGET' === ($options['channel'] ?? null))
            || ('widget' === ($classification['source'] ?? null))
            || !empty($classification['is_widget_mode']);

        $disabledByUser = false;
        if (!$incognito && !$disabledByRequest) {
            $user = $this->em->getRepository(User::class)->find($message->getUserId());
            $disabledByUser = !$user || !$user->isMemoriesEnabled();
        }

        $this->pendingMemoryExtraction->dispatch($this->pendingMemoryExtraction->build(
            $message,
            '',
            $thread,
            [],
            $disabledByRequest,
            $disabledByUser,
            incognito: $incognito,
        ));
    }
}
