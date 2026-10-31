<?php

declare(strict_types=1);

namespace App\Service\Usage;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a Synaplan Demo outcome into BUSELOG for the Demo account.
 *
 * Refusals never reach the model, so they would otherwise leave the
 * statistics page blank. The row is the action DEMO, which the message
 * allowance does not count.
 */
final readonly class UsageOutcomeLog
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function refusal(int $userId, string $code, string $subject, string $installId, string $alias, int $http, string $path): void
    {
        if ($userId < 1) {
            return;
        }

        $this->insert(UsageOutcome::refusal($userId, time(), $code, $subject, $installId, $alias, $http, $path));
    }

    public function issued(int $userId, string $installId): void
    {
        if ($userId < 1) {
            return;
        }

        $this->insert(UsageOutcome::issued($userId, time(), $installId));
    }

    /**
     * @param array<string, int|string|null> $params
     */
    private function insert(array $params): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS,
             BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS,
             BESTIMATED, BMODEL_ID, BPRICE_SNAPSHOT,
             BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
             VALUES (:user_id, :timestamp, :action, :provider, :model, :tokens,
             :prompt_tokens, :completion_tokens, :cached_tokens, :cache_creation_tokens,
             :estimated, :model_id, :price_snapshot,
             :cost, :latency, :status, :error, :metadata)',
            $params,
        );
    }
}
