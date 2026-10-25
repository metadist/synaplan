<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Server-sent events for the updates a run has already recorded.
 *
 * A step that waits for a worker closes the stream with a `queued` frame.
 * It is not recorded and has no id, so the client's Last-Event-ID stays on
 * the last recorded update.
 */
final class ResearchEventStream
{
    /**
     * @param list<array{seq: int, event: string, data: array<string, mixed>}> $events
     */
    public static function body(array $events, ?int $queuedSince = null): string
    {
        $out = '';
        foreach ($events as $event) {
            $json = json_encode($event['data'], JSON_THROW_ON_ERROR);
            $out .= 'id: '.$event['seq']."\n";
            $out .= 'event: '.$event['event']."\n";
            $out .= 'data: '.$json."\n\n";
        }
        if (null !== $queuedSince) {
            $out .= "event: queued\n";
            $out .= 'data: '.json_encode(['since_seconds' => $queuedSince], JSON_THROW_ON_ERROR)."\n\n";
        }

        return $out;
    }
}
