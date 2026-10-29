<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PlainText;

/**
 * Tells the person which statements the fact-check changed and why, in the
 * checker's words. Supported statements are not listed: the closing count
 * covers them. A long list is cut, so the bubble stays readable.
 */
final readonly class CheckVerdictEvents
{
    private const MAX_SHOWN = 8;

    /**
     * @param array{drop: list<array{sentence: string, text: string, reason: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string, note?: string}>                                                                                       $verdicts
     */
    public static function announce(StageContext $ctx, array $sorted, array $verdicts): void
    {
        $shown = 0;
        foreach ($sorted['drop'] as $item) {
            if ($shown >= self::MAX_SHOWN) {
                return;
            }
            // The draft check names a code (`uncited`, `number`, …); the client puts it into words.
            self::append($ctx, $item['text'], 'removed', ['reason' => $item['reason']]);
            ++$shown;
        }
        foreach ($sorted['ask'] as $item) {
            $row = $verdicts[$item['id']] ?? ['verdict' => 'unsupported', 'part' => '', 'note' => ''];
            if ('supported' === $row['verdict']) {
                continue;
            }
            if ($shown >= self::MAX_SHOWN) {
                return;
            }
            self::append($ctx, $item['bare'], $row['verdict'], [
                'note' => PlainText::line($row['note'] ?? '', 300),
                'part' => PlainText::line($row['part'], 200),
            ]);
            ++$shown;
        }
    }

    /**
     * @param array<string, string> $details only the filled ones travel
     */
    private static function append(StageContext $ctx, string $statement, string $verdict, array $details): void
    {
        $event = [
            'statement' => PlainText::line($statement, 300),
            'verdict' => $verdict,
        ];
        foreach ($details as $key => $value) {
            if ('' !== $value) {
                $event[$key] = $value;
            }
        }
        $ctx->events->append($ctx->state->ref->id, 'verdict', $event);
    }
}
