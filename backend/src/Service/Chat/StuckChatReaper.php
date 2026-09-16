<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Moves chat messages and files that never left a non-terminal state to
 * `error` after a TTL. The in-flight request is the fast path; this is the
 * guarantee after a worker restart or a lost Messenger message (issue #1913).
 */
final readonly class StuckChatReaper
{
    public const MESSAGE_TTL_SECONDS = 1800;
    public const FILE_TTL_SECONDS = 1800;

    /** @var list<string> */
    public const MESSAGE_STATUSES = ['processing', 'queued'];

    /** @var list<string> */
    public const FILE_STATUSES = ['extracting', 'vectorizing'];

    public function __construct(
        private MessageRepository $messages,
        private FileRepository $files,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{messages: int, files: int}
     */
    public function reap(?int $now = null): array
    {
        $now ??= time();
        $messages = 0;
        $files = 0;

        foreach ($this->messages->findStaleNonTerminal(
            $now - self::MESSAGE_TTL_SECONDS,
            self::MESSAGE_STATUSES,
        ) as $message) {
            $this->failMessage($message);
            ++$messages;
        }

        foreach ($this->files->findStaleProcessing(
            $now - self::FILE_TTL_SECONDS,
            self::FILE_STATUSES,
        ) as $file) {
            $this->failFile($file);
            ++$files;
        }

        if ($messages + $files > 0) {
            $this->em->flush();
        }

        return ['messages' => $messages, 'files' => $files];
    }

    private function failMessage(Message $message): void
    {
        $message->setStatus('error');
        if ('' !== trim($message->getText())) {
            return;
        }

        $lang = $message->getLanguage();
        $message->setText($this->translator->trans(
            'reason.timeout',
            [],
            'ai_errors',
            '' !== $lang ? $lang : 'en',
        ));
    }

    private function failFile(File $file): void
    {
        $wasVectorizing = 'vectorizing' === $file->getStatus()
            || File::VECTOR_STATE_PENDING === $file->getVectorState();
        $file->setStatus('error');
        if ($wasVectorizing) {
            $file->setVectorState(File::VECTOR_STATE_FAILED);
        }
    }
}
