<?php

declare(strict_types=1);

namespace App\AI\Messages;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The answer a gateway turn gets when the gateway started a research
 * instead of asking the model: one assistant message carrying the notice
 * sentence, shaped exactly like an upstream reply (JSON or the Anthropic
 * SSE event sequence) so no client needs a special case to show it.
 */
final class GatewayResearchReply
{
    /**
     * @param array<string, mixed> $prepared
     */
    public static function respond(array $prepared, GatewayEffortRating $rating): Response
    {
        $runId = (string) $rating->decision->runId;
        $message = self::message($prepared, $runId, (string) $rating->notice);
        $response = !empty($prepared['stream']) ? self::streamed($message) : new JsonResponse($message);
        foreach (\is_array($prepared['headers'] ?? null) ? $prepared['headers'] : [] as $name => $value) {
            $response->headers->set((string) $name, (string) $value);
        }
        foreach ($rating->headers() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $prepared
     *
     * @return array<string, mixed>
     */
    private static function message(array $prepared, string $runId, string $text): array
    {
        $resolved = \is_array($prepared['resolved'] ?? null) ? $prepared['resolved'] : [];
        $model = \is_string($resolved['displayModel'] ?? null) ? $resolved['displayModel'] : '';

        return [
            'id' => 'msg_research_'.$runId,
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function streamed(array $message): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->setCallback(static function () use ($message): void {
            foreach (self::events($message) as $event) {
                echo $event;
            }
            flush();
        });

        return $response;
    }

    /**
     * The Anthropic SSE sequence for a single text block.
     *
     * @param array<string, mixed> $message
     *
     * @return list<string>
     */
    public static function events(array $message): array
    {
        $content = \is_array($message['content'] ?? null) ? $message['content'] : [];
        $text = \is_array($content[0] ?? null) && \is_string($content[0]['text'] ?? null) ? $content[0]['text'] : '';
        $start = $message;
        $start['content'] = [];
        $start['stop_reason'] = null;

        return [
            self::event('message_start', ['type' => 'message_start', 'message' => $start]),
            self::event('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]),
            self::event('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]]),
            self::event('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]),
            self::event('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 0]]),
            self::event('message_stop', ['type' => 'message_stop']),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function event(string $name, array $data): string
    {
        return 'event: '.$name."\ndata: ".json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n\n";
    }
}
