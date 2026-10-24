<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** Server-sent events for the updates a run has already recorded. */
final class ResearchEventStream
{
    /**
     * @param list<array{seq: int, event: string, data: array<string, mixed>}> $events
     */
    public static function body(array $events): string
    {
        $out = '';
        foreach ($events as $event) {
            $json = json_encode($event['data'], JSON_THROW_ON_ERROR);
            $out .= 'id: '.$event['seq']."\n";
            $out .= 'event: '.$event['event']."\n";
            $out .= 'data: '.$json."\n\n";
        }

        return $out;
    }
}
