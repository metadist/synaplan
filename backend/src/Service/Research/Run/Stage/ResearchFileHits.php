<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\Source;

/**
 * One file search for a plan point, recorded on the ledger. A new file is
 * added only while sources can still be read. A file the ledger already has
 * is returned again, so the lead sees it under its known id.
 */
final class ResearchFileHits
{
    /**
     * @return list<Source>
     */
    public static function gather(ResearchFileSearch $files, StageContext $ctx, string $pointId, string $query): array
    {
        $found = $files->find(
            $ctx->state->ref->userId,
            $query,
            max(1, $ctx->state->budget->sourcesLeft()),
            $ctx->state->knowledgeKeys,
        );
        $ctx->state->ledger->addQuery($pointId, $query, 'files', count($found));
        $ctx->events->append($ctx->state->ref->id, 'search', [
            'point' => $pointId,
            'query' => $query,
            'scope' => 'files',
            'results' => count($found),
        ]);

        $sources = [];
        foreach ($found as $row) {
            if (!$ctx->state->budget->canRead()) {
                break;
            }
            $before = count($ctx->state->ledger->sources());
            $source = $ctx->state->ledger->source($ctx->state->ledger->addSource(Source::file($row['file_id'], $row['title'])));
            if (null === $source) {
                continue;
            }
            if (count($ctx->state->ledger->sources()) > $before) {
                $ctx->events->append($ctx->state->ref->id, 'source', $source->toEvent());
            }
            $sources[] = $source;
        }

        return $sources;
    }
}
