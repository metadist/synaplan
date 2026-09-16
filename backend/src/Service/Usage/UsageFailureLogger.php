<?php

declare(strict_types=1);

namespace App\Service\Usage;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Writes a BUSELOG row for an AI call that failed.
 *
 * BUSELOG has carried BSTATUS and BERROR columns from the start, but every
 * writer so far hard-coded 'success' — so the table recorded what a model cost
 * and never what it broke. Failures land here with BTOKENS = 0 and BCOST = 0
 * so nothing is billed, which keeps the statistics untouched while giving the
 * status page a history that survives a Redis flush.
 *
 * Deliberately narrow (a DBAL connection and nothing else) so it can be called
 * from inside a failing AI call without dragging the pricing stack along.
 */
final readonly class UsageFailureLogger
{
    private const MAX_ERROR_LENGTH = 1000;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        int $userId,
        string $action,
        string $provider,
        string $model,
        ?int $modelId,
        string $failureKind,
        string $errorMessage,
        array $metadata = [],
    ): void {
        if ($userId <= 0) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS,
                 BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS,
                 BESTIMATED, BMODEL_ID, BPRICE_SNAPSHOT, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
                 VALUES (:user_id, :timestamp, :action, :provider, :model, 0,
                 0, 0, 0, 0,
                 0, :model_id, NULL, :cost, 0, :status, :error, :metadata)',
                [
                    'user_id' => $userId,
                    'timestamp' => time(),
                    'action' => $action,
                    'provider' => $provider,
                    'model' => mb_substr($model, 0, 128),
                    'model_id' => $modelId,
                    'cost' => '0.000000',
                    'status' => 'error',
                    'error' => mb_substr($errorMessage, 0, self::MAX_ERROR_LENGTH),
                    'metadata' => json_encode(
                        $metadata + ['failure_kind' => $failureKind],
                        \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                    ),
                ]
            );
        } catch (\Throwable $e) {
            // The call this describes has already failed; failing to write the
            // audit row must not replace the real error with a database one.
            $this->logger->warning('Failed to record AI usage failure', [
                'action' => $action,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
