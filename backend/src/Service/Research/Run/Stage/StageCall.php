<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * One paid call, and a single retry when the answer is not the JSON shape.
 * Stop and an exhausted budget are checked before each attempt. A model that
 * cannot be resolved is not retried: nothing was called, so nothing is charged.
 */
final readonly class StageCall
{
    /** @var array<string, string> */
    private const LEDGER_ROLE = [
        'sorting' => 'sorter',
        'planning' => 'lead',
        'reading' => 'reader',
        'writing' => 'writer',
        'checker' => 'checker',
    ];

    public function __construct(private ResearchModelCaller $models)
    {
    }

    public function once(
        StageContext $ctx,
        string $role,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
    ): StageCallResult {
        $reply = $this->attempt($ctx, $role, $modelId, $system, $user, $schema);
        if ($reply instanceof StageResult) {
            return StageCallResult::stop($reply);
        }
        if (self::usable($reply, $schema)) {
            return StageCallResult::reply($reply);
        }
        if (!$reply->called) {
            return StageCallResult::stop(StageEnds::roleUnavailable($ctx, $role));
        }
        if (null === $schema) {
            return StageCallResult::stop(StageEnds::model($ctx, $role));
        }

        $retry = $this->attempt($ctx, $role, $modelId, $system."\n\nReturn JSON only, in the required shape.", $user, $schema);
        if ($retry instanceof StageResult) {
            return StageCallResult::stop($retry);
        }
        if (!self::usable($retry, $schema)) {
            return StageCallResult::stop(StageEnds::model($ctx, $role));
        }

        return StageCallResult::reply($retry);
    }

    private function attempt(
        StageContext $ctx,
        string $role,
        int $modelId,
        string $system,
        string $user,
        ?StructuredOutputSchema $schema,
    ): ResearchModelReply|StageResult {
        $blocked = StageEnds::beforeCall($ctx);
        if ($blocked instanceof StageResult) {
            return $blocked;
        }

        $reply = $this->models->answer($ctx->state->ref->userId, $modelId, $system, $user, $schema);
        if ($reply->called) {
            $ctx->state->budget->spend('calls');
            if (null !== $reply->cost) {
                $ctx->state->budget->addCost($reply->cost);
            }
            $ledgerRole = self::LEDGER_ROLE[$role] ?? null;
            if (null !== $ledgerRole && '' !== $reply->modelKey) {
                $ctx->state->ledger->setModel($ledgerRole, $reply->modelKey);
            }
        }

        return $reply;
    }

    /**
     * One prose call. A model that answers nothing does not end the run:
     * the caller keeps the text it already has. A stop or an exhausted
     * budget still ends it.
     */
    public function prose(
        StageContext $ctx,
        string $role,
        int $modelId,
        string $system,
        string $user,
    ): ResearchModelReply|StageResult|null {
        $reply = $this->attempt($ctx, $role, $modelId, $system, $user, null);
        if ($reply instanceof StageResult) {
            return $reply;
        }
        if (!$reply->ok || '' === trim($reply->text)) {
            return null;
        }

        return $reply;
    }

    private static function usable(ResearchModelReply $reply, ?StructuredOutputSchema $schema): bool
    {
        if (!$reply->ok) {
            return false;
        }

        return null === $schema || is_array($reply->data);
    }
}
