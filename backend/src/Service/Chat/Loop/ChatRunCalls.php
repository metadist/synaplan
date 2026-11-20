<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\ToolCalling\ToolCallParser;

/**
 * Reads the tool calls of a model answer in the provider's wire shape into
 * the calls a chat run queues. A call without a name is dropped; a call
 * without an id gets one, so its result can answer it.
 */
final class ChatRunCalls
{
    /**
     * @return list<array{id: string, name: string, args: array<string, mixed>, signature?: string}>
     */
    public static function read(ToolCallParser $parser, mixed $wire): array
    {
        $out = [];
        foreach ($parser->fromWireToolCalls($wire) as $call) {
            if ('' === $call->name) {
                continue;
            }
            $row = ['id' => '' !== $call->id ? $call->id : 'call_'.bin2hex(random_bytes(6)), 'name' => $call->name, 'args' => $call->arguments];
            if (null !== $call->thoughtSignature && '' !== $call->thoughtSignature) {
                $row['signature'] = $call->thoughtSignature;
            }
            $out[] = $row;
        }

        return $out;
    }
}
