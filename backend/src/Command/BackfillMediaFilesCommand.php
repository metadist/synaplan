<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\Media\GeneratedFileRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill BFILES rows for legacy generated media that only ever lived on
 * BMESSAGES.BFILEPATH (03_file-management.md §3.2). Idempotent and resumable:
 * a row is created only when no BFILES row already exists for the same
 * (user, path), so re-running is safe. Documents already created BFILES rows,
 * so this targets image/video/audio/calendar media that the old sync path left
 * invisible to the file manager's Generated gallery.
 */
#[AsCommand(
    name: 'app:files:backfill-media',
    description: 'Create missing BFILES rows for legacy generated media referenced only by BMESSAGES.BFILEPATH',
)]
final class BackfillMediaFilesCommand extends Command
{
    private const BATCH = 200;

    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly FileRepository $fileRepository,
        private readonly GeneratedFileRegistrar $registrar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be created without writing any rows')
            ->setHelp(
                "Walks BMESSAGES rows that carry a media file (file=1, non-empty BFILEPATH) and,\n".
                "for each one that has no matching BFILES row, registers it as source=generated\n".
                "with the right origin kind + message link.\n\n".
                'Idempotent: existing BFILES rows are never duplicated. Use --dry-run to preview.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $created = 0;
        $skipped = 0;
        $afterId = 0;

        while (true) {
            $messages = $this->messageRepository->findFileMessagesAfter($afterId, self::BATCH);
            if (empty($messages)) {
                break;
            }

            foreach ($messages as $message) {
                $afterId = max($afterId, (int) $message->getId());

                $path = $message->getFilePath();
                $kind = $this->originKindFor($message->getFileType(), $path);
                if (null === $kind) {
                    continue; // not media (documents already have rows)
                }

                $existing = $this->fileRepository->findOneBy([
                    'userId' => $message->getUserId(),
                    'filePath' => $path,
                ]);
                if (null !== $existing) {
                    ++$skipped;
                    continue;
                }

                if ($dryRun) {
                    ++$created;
                    continue;
                }

                $file = $this->registrar->register(
                    $message->getUserId(),
                    $path,
                    '' !== $message->getFileType() ? $message->getFileType() : $kind,
                    $message->getId(),
                );
                if (null !== $file) {
                    ++$created;
                } else {
                    ++$skipped;
                }
            }
        }

        $io->success(sprintf(
            '%s %d media file row(s); %d already present/skipped.',
            $dryRun ? 'Would create' : 'Created',
            $created,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * Resolve the generated origin kind for a legacy message-file, or null when
     * it is not media (documents are excluded — they already get BFILES rows).
     */
    private function originKindFor(string $type, string $path): ?string
    {
        $type = strtolower($type);
        if (in_array($type, ['image', 'video', 'audio', 'calendar'], true)) {
            return $type;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' => 'image',
            'mp4', 'webm', 'mov', 'avi', 'mkv' => 'video',
            'mp3', 'wav', 'ogg', 'm4a' => 'audio',
            'ics' => 'calendar',
            default => null,
        };
    }
}
