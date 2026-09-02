<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\AiFacade;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * (Re)builds the `routing_anchors` Qdrant collection from
 * {@see SystemCapabilityRegistry::exampleUtterances} — the anchors the Phase 8
 * embedding-router cascade layer ({@see \App\Service\Message\Routing\EmbeddingRouterService})
 * matches incoming messages against.
 *
 * Wipes and re-embeds the entire (small, ~15-point) collection on every run
 * rather than diffing: the source of truth is code
 * ({@see SystemCapabilityRegistry}), not the collection itself, so a
 * reworded or removed example utterance must never leave a stale anchor
 * behind. Re-run this after editing `SystemCapabilityRegistry::$capabilities`
 * or switching the embedding model (`DEFAULTMODEL.VECTORIZE`).
 *
 * Deliberately NOT wired into `app:seed` or the Docker entrypoint: it makes
 * LIVE embedding calls (small, cheap, but real) and the embedding-router
 * feature flag defaults OFF ({@see \App\Service\Message\Routing\EmbeddingRouterConfig}),
 * so an empty/stale anchors collection has zero production impact until an
 * operator both runs this command AND turns the flag on.
 */
#[AsCommand(
    name: 'app:routing:sync-anchors',
    description: 'Rebuild the Qdrant routing-anchor collection from SystemCapabilityRegistry example utterances (Phase 8 embedding-router; LIVE embedding calls)',
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('routing-anchors-sync', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another routing-anchors sync is already in progress, skipping.');

            return Command::SUCCESS;
        }

        try {
            $deleted = $this->qdrant->deleteAllRoutingAnchors();
            $io->writeln(sprintf('Cleared %d existing routing anchor(s).', $deleted));

            $rows = [];
            $upserted = 0;
            $failed = 0;

            foreach ($this->capabilityRegistry->all() as $capability) {
                foreach ($capability->exampleUtterances as $utterance) {
                    try {
                        $embedResult = $this->aiFacade->embed($utterance);
                        $vector = $embedResult['embedding'];
                        if ([] === $vector) {
                            throw new \RuntimeException('Embedding provider returned an empty vector.');
                        }

                        $pointId = sprintf('route_%s_%s', $capability->topic, md5($utterance));
                        $this->qdrant->upsertRoutingAnchor($pointId, $vector, [
                            'topic' => $capability->topic,
                            'intent' => $capability->intent,
                            'utterance' => $utterance,
                        ]);

                        ++$upserted;
                        $rows[] = [$capability->topic, $utterance, '<info>OK</info>'];
                    } catch (\Throwable $e) {
                        ++$failed;
                        $rows[] = [$capability->topic, $utterance, '<error>FAILED: '.$e->getMessage().'</error>'];
                    }
                }
            }

            $io->table(['topic', 'utterance', 'result'], $rows);
            $io->success(sprintf('Routing anchors synced: %d upserted, %d failed.', $upserted, $failed));

            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
