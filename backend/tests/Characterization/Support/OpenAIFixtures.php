<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use GuzzleHttp\Psr7\Response;
use OpenAI\Responses\Models\RetrieveResponse as ModelResponse;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;

/**
 * OpenAI API answers for the provider tests, built from the SDK's own
 * fixtures so the SDK parses them as it parses the real ones.
 */
final class OpenAIFixtures
{
    public const USAGE = ['input_tokens' => 21, 'input_tokens_details' => ['cached_tokens' => 4], 'output_tokens' => 9, 'output_tokens_details' => ['reasoning_tokens' => 3], 'total_tokens' => 30];

    public static function model(string $id): Response
    {
        return self::json(ModelResponse::fake(['id' => $id])->toArray());
    }

    /**
     * @param list<array<string, mixed>> $output
     * @param array<string, mixed>       $override
     *
     * @return array<string, mixed>
     */
    public static function responseArray(array $output, array $override = []): array
    {
        return CreateResponse::fake(array_replace(['id' => 'resp_1', 'output' => $output, 'tools' => [], 'usage' => self::USAGE], $override), null, OverrideStrategy::Replace)->toArray();
    }

    /**
     * @param list<array<string, mixed>> $output
     * @param array<string, mixed>       $override
     */
    public static function response(array $output, array $override = []): Response
    {
        return self::json(self::responseArray($output, $override));
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(string $text): array
    {
        return ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function functionCall(string $name, string $arguments, string $callId): array
    {
        return ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => $callId, 'name' => $name, 'arguments' => $arguments, 'status' => 'completed'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public static function error(int $status, string $message, string $type = 'invalid_request_error', ?string $code = null): Response
    {
        return self::json(['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => $code]], $status);
    }

    /**
     * @param array<string, mixed> ...$events
     */
    public static function sse(array ...$events): Response
    {
        $body = '';
        foreach ($events as $number => $event) {
            $body .= 'data: '.json_encode($event + ['sequence_number' => $number + 1])."\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }
}
