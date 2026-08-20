<?php

declare(strict_types=1);

namespace App\Seed;

use App\Model\ModelCatalog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Applies {@see ModelCatalog}::RETIREMENTS to BMODELS on every deploy.
 *
 * This is the seeder that replaces the hand-written retirement migration
 * (#1515). Recording a shutdown is now one array entry in the catalog; this
 * turns that entry into the three facts a row has to carry:
 *
 *   - BRETIREDON   — when it died, which is what distinguishes "dead upstream"
 *                    from "an operator switched this off".
 *   - BSUCCESSORID — what replaces it, resolved from the catalog key so the
 *                    registry never stores a BID that drifted.
 *   - BACTIVE / BSELECTABLE / BISDEFAULT = 0 — it cannot serve a request, so it
 *                    must not be offered, chosen or billed.
 *
 * Why this is NOT part of {@see ModelSeeder}: that seeder deliberately treats
 * BACTIVE, BSELECTABLE and BISDEFAULT as operator-owned and never overwrites
 * them, which is correct for a live model and wrong for a dead one. A retirement
 * outranks an operator preference — keeping "please offer this model" on a model
 * the provider has switched off only produces a request that fails. Separating
 * the two keeps that override explicit and auditable instead of punching a hole
 * in ModelSeeder's preservation rules.
 *
 * Idempotence and safety:
 *   - The UPDATE is guarded on BPROVID, so a BID an operator repurposed for a
 *     different model is left alone.
 *   - Only rows that are not already in the target state are written, so a
 *     re-run reports `skipped` and issues no SQL.
 *   - Rows absent from BMODELS are skipped, not inserted: a retired model that
 *     an install never had is nothing to record.
 */
final readonly class ModelRetirementSeeder
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function seed(): SeedResult
    {
        $retirements = ModelCatalog::retirements();
        if ([] === $retirements) {
            return new SeedResult('model-retirements', 0);
        }

        $existing = $this->loadRows(array_keys($retirements));
        $updated = 0;
        $skipped = 0;

        foreach ($retirements as $bid => $record) {
            $row = $existing[$bid] ?? null;
            if (null === $row) {
                ++$skipped;
                continue;
            }

            if ($row['BPROVID'] !== $record['providerId']) {
                // The BID now stands for something else than the model we
                // retired. Writing a retirement date onto it would mark a live
                // model dead.
                $this->logger->warning('Model retirement skipped: BID no longer holds the retired model', [
                    'bid' => $bid,
                    'expectedProviderId' => $record['providerId'],
                    'actualProviderId' => $row['BPROVID'],
                ]);
                ++$skipped;
                continue;
            }

            $successorBid = ModelCatalog::successorBid($bid);

            if ($this->isUpToDate($row, $record['retiredOn'], $successorBid)) {
                ++$skipped;
                continue;
            }

            $this->apply($bid, $record['providerId'], $record['retiredOn'], $successorBid);
            ++$updated;

            $this->logger->info('Model retired', [
                'bid' => $bid,
                'providerId' => $row['BPROVID'],
                'retiredOn' => $record['retiredOn'],
                'successorBid' => $successorBid,
                'reason' => $record['reason'],
            ]);
        }

        return new SeedResult('model-retirements', 0, $updated, $skipped);
    }

    /**
     * @param int[] $bids
     *
     * @return array<int, array{BPROVID: string, BRETIREDON: string|null, BSUCCESSORID: int|null, BACTIVE: int, BSELECTABLE: int, BISDEFAULT: int}> indexed by BID
     */
    private function loadRows(array $bids): array
    {
        $result = $this->connection->fetchAllAssociative(
            'SELECT BID, BPROVID, BRETIREDON, BSUCCESSORID, BACTIVE, BSELECTABLE, BISDEFAULT'
            .' FROM BMODELS WHERE BID IN (:bids)',
            ['bids' => $bids],
            ['bids' => ArrayParameterType::INTEGER],
        );

        $rows = [];
        foreach ($result as $row) {
            $rows[(int) $row['BID']] = [
                'BPROVID' => (string) $row['BPROVID'],
                'BRETIREDON' => null === $row['BRETIREDON'] ? null : (string) $row['BRETIREDON'],
                'BSUCCESSORID' => null === $row['BSUCCESSORID'] ? null : (int) $row['BSUCCESSORID'],
                'BACTIVE' => (int) $row['BACTIVE'],
                'BSELECTABLE' => (int) $row['BSELECTABLE'],
                'BISDEFAULT' => (int) $row['BISDEFAULT'],
            ];
        }

        return $rows;
    }

    /**
     * @param array{BRETIREDON: string|null, BSUCCESSORID: int|null, BACTIVE: int, BSELECTABLE: int, BISDEFAULT: int} $row
     */
    private function isUpToDate(array $row, string $retiredOn, ?int $successorBid): bool
    {
        return $retiredOn === $row['BRETIREDON']
            && $successorBid === $row['BSUCCESSORID']
            && 0 === $row['BACTIVE']
            && 0 === $row['BSELECTABLE']
            && 0 === $row['BISDEFAULT'];
    }

    /**
     * The BPROVID guard is repeated here even though the caller already checked
     * it against the SELECT. `app:seed` runs on every container start, and
     * production is a three-node Galera cluster sharing one schema, so another
     * node writing between our SELECT and this UPDATE is the normal case rather
     * than a hypothetical. Keying the write on BID alone would let that race
     * stamp a retirement onto a row that has since become a different model.
     */
    private function apply(int $bid, string $providerId, string $retiredOn, ?int $successorBid): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE BMODELS
               SET BRETIREDON = :retiredOn,
                   BSUCCESSORID = :successorBid,
                   BACTIVE = 0,
                   BSELECTABLE = 0,
                   BISDEFAULT = 0
             WHERE BID = :bid
               AND BPROVID = :providerId
        SQL, [
            'retiredOn' => $retiredOn,
            'successorBid' => $successorBid,
            'bid' => $bid,
            'providerId' => $providerId,
        ]);
    }
}
