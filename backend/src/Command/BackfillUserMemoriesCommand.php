<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use App\Service\FeedbackConstants;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:memories:backfill-sql',
    description: 'Backfill the authoritative SQL memory store from existing Qdrant points.',
)]
final class BackfillUserMemoriesCommand extends Command
{
    private const DEFAULT_LIMIT = 50000;

    public function __construct(
        private readonly QdrantClientInterface $qdrantClient,
        private readonly UserMemoryRepository $memoryRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Persist the discovered memories. Without this flag the command is read-only.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of Qdrant points to inspect.',
                (string) self::DEFAULT_LIMIT,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limitRaw = (string) $input->getOption('limit');

        if (!ctype_digit($limitRaw) || (int) $limitRaw < 1) {
            $io->error('--limit must be a positive integer.');

            return Command::INVALID;
        }

        $limit = (int) $limitRaw;
        $namespaces = [
            null,
            FeedbackConstants::NAMESPACE_FALSE_POSITIVE,
            FeedbackConstants::NAMESPACE_POSITIVE,
        ];
        $scanned = 0;
        $candidates = 0;
        $existing = 0;
        $invalid = 0;

        $io->title('User memory SQL backfill');
        $io->note($apply ? 'Apply mode: SQL rows will be inserted.' : 'Dry-run mode: no SQL rows will be inserted.');

        foreach ($namespaces as $namespace) {
            $remaining = $limit - $scanned;
            if ($remaining <= 0) {
                break;
            }

            $points = $this->qdrantClient->scrollAllMemoriesForReindex($remaining, $namespace);
            foreach ($points as $point) {
                ++$scanned;
                $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
                $memoryId = $this->resolveMemoryId($point, $payload);
                $userId = (int) ($payload['user_id'] ?? 0);

                if ($memoryId <= 0 || $userId <= 0) {
                    ++$invalid;
                    continue;
                }
                if (null !== $this->memoryRepository->find($memoryId)) {
                    ++$existing;
                    continue;
                }

                ++$candidates;
                if (!$apply) {
                    continue;
                }

                $memory = new UserMemory(
                    id: $memoryId,
                    userId: $userId,
                    category: (string) ($payload['category'] ?? 'personal'),
                    key: (string) ($payload['key'] ?? 'legacy_memory'),
                    value: (string) ($payload['value'] ?? ''),
                    source: (string) ($payload['source'] ?? UserMemory::SOURCE_AUTO_DETECTED),
                    messageId: isset($payload['message_id']) ? (int) $payload['message_id'] : null,
                    namespace: $namespace,
                    active: (bool) ($payload['active'] ?? true),
                    created: (int) ($payload['created'] ?? time()),
                    updated: (int) ($payload['updated'] ?? time()),
                );
                $this->memoryRepository->save($memory, flush: false);
            }
        }

        if ($apply && $candidates > 0) {
            $this->memoryRepository->flush();
        }

        $io->definitionList(
            ['Points scanned' => (string) $scanned],
            ['New SQL rows' => (string) $candidates],
            ['Already present' => (string) $existing],
            ['Invalid points skipped' => (string) $invalid],
        );

        if (!$apply && $candidates > 0) {
            $io->note('Re-run with --apply after reviewing this dry-run.');
        }

        return $invalid > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $point
     * @param array<string, mixed> $payload
     */
    private function resolveMemoryId(array $point, array $payload): int
    {
        $logicalId = (string) ($payload['_point_id'] ?? $point['id'] ?? '');
        if (preg_match('/^mem_\d+_(\d+)$/', $logicalId, $matches)) {
            return (int) $matches[1];
        }

        return isset($payload['id']) ? (int) $payload['id'] : 0;
    }
}
