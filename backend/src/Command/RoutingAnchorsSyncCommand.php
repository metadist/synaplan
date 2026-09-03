<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\AiFacade;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * (Re)builds the `routing_anchors` Qdrant collection from
 * {@see SystemCapabilityRegistry::exampleUtterances} — the anchors the Phase 8
 * embedding-router cascade layer ({@see \App\Service\Message\Routing\EmbeddingRouterService})
 * matches incoming messages against.
 *
 * The source of truth is code ({@see SystemCapabilityRegistry}), not the
 * collection, so a reworded or removed example utterance must not leave a
 * stale anchor behind. That pruning happens AFTER the fresh anchors are
 * upserted, never before: anchor point ids are deterministic
 * (`route_{topic}_{md5(utterance)}`), so an unchanged utterance is overwritten
 * in place, and a failed embedding leaves the previous anchor for that
 * utterance intact instead of emptying the collection. Re-run this after
 * editing `SystemCapabilityRegistry::$capabilities` or switching the embedding
 * model (`DEFAULTMODEL.VECTORIZE`).
 *
 * Deliberately NOT wired into `app:seed` or the Docker entrypoint: it makes
 * LIVE embedding calls (small, cheap, but real) and the embedding-router
 * feature flag defaults OFF ({@see \App\Service\Message\Routing\EmbeddingRouterConfig}),
 * so an empty/stale anchors collection has zero production impact until an
 * operator both runs this command AND turns the flag on.
 */
#[AsCommand(
    name: 'app:routing:sync-anchors',
    description: 'Rebuild the Qdrant routing-anchor collection from SystemCapabilityRegistry example utterances (embedding-router; LIVE embedding calls)',
)]
final class RoutingAnchorsSyncCommand extends Command
{
    private const LOCK_TTL_SECONDS = 600;

    public function __construct(
        private readonly SystemCapabilityRegistry $capabilityRegistry,
        private readonly AiFacade $aiFacade,
        private readonly QdrantClientInterface $qdrant,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'List the anchors that would be written without embedding or touching Qdrant',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            return $this->dryRun($io);
        }

        $lock = $this->lockFactory->createLock('routing-anchors-sync', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->note('Another routing-anchors sync is already in progress, skipping.');

            return Command::SUCCESS;
        }

        try {
            $rows = [];
            $keepPointIds = [];
            $failed = 0;

            foreach ($this->capabilityRegistry->all() as $capability) {
                foreach ($capability->exampleUtterances as $utterance) {
                    $pointId = self::pointId($capability->topic, $utterance);

                    try {
                        $embedResult = $this->aiFacade->embed($utterance);
                        $vector = $embedResult['embedding'];
                        if ([] === $vector) {
                            throw new \RuntimeException('Embedding provider returned an empty vector.');
                        }

                        $this->qdrant->upsertRoutingAnchor($pointId, $vector, [
                            'topic' => $capability->topic,
                            'intent' => $capability->intent,
                            'utterance' => $utterance,
                        ]);

                        $keepPointIds[] = $pointId;
                        $rows[] = [$capability->topic, $utterance, '<info>OK</info>'];
                    } catch (\Throwable $e) {
                        ++$failed;
                        // Keep the id: the previous anchor for this utterance
                        // (if any) is still valid and better than none.
                        $keepPointIds[] = $pointId;
                        $rows[] = [$capability->topic, $utterance, '<error>FAILED: '.$e->getMessage().'</error>'];
                    }
                }
            }

            $io->table(['topic', 'utterance', 'result'], $rows);

            $upserted = count($rows) - $failed;
            $pruned = $this->qdrant->deleteRoutingAnchorsExcept($keepPointIds);
            $io->writeln(sprintf('Pruned %d stale routing anchor(s).', $pruned));

            if ($failed > 0) {
                $io->error(sprintf(
                    '%d of %d routing anchor(s) could not be embedded. The previously synced version of each '
                    .'was kept rather than pruned, so routing still works — but any anchor that never synced '
                    .'is missing. Check the embedding model (DEFAULTMODEL.VECTORIZE) and re-run.',
                    $failed,
                    count($rows),
                ));

                return Command::FAILURE;
            }

            $io->success(sprintf('Routing anchors synced: %d upserted, %d pruned.', $upserted, $pruned));

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function dryRun(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->capabilityRegistry->all() as $capability) {
            foreach ($capability->exampleUtterances as $utterance) {
                $rows[] = [$capability->topic, $utterance, self::pointId($capability->topic, $utterance)];
            }
        }

        $io->table(['topic', 'utterance', 'point id'], $rows);
        $io->note(sprintf(
            'Dry run: %d anchor(s) would be embedded and upserted, and every other anchor in the collection '
            .'would be pruned. No embedding call was made and Qdrant was not touched.',
            count($rows),
        ));

        return Command::SUCCESS;
    }

    /**
     * Deterministic so a re-sync of an unchanged utterance overwrites its
     * anchor in place instead of creating a duplicate.
     */
    private static function pointId(string $topic, string $utterance): string
    {
        return sprintf('route_%s_%s', $topic, md5($utterance));
    }
}
