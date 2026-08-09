<?php

declare(strict_types=1);

/**
 * Minimal Anthropic-shaped upstream for Messages gateway smoke tests.
 *
 * Usage:
 *   php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
 *
 * Select a transcript with the X-Fixture request header:
 *   complete (default) | stream | tool-use | client-tool | web-search |
 *   error-429 | error-401 | echo
 *
 * For tool-use: the first request returns a tool_use turn targeting the first
 * mcp__* tool in the request's tools[] (or mcp__1__rag_search). A follow-up
 * request that already contains tool_result blocks returns end_turn.
 *
 * For client-tool: the same shape, but targeting the first tool the *client*
 * declared. The gateway must relay that turn to the client instead of trying to
 * run it, so a caller can prove Claude-Code-style tool calling round-trips.
 *
 * For web-search: the first request calls Synaplan's native `web_search` tool
 * (and reports an error turn if the gateway did not inject it). The follow-up
 * answers with the tool result verbatim, so a caller can prove the search
 * output actually reached the model.
 */

$fixturesDir = __DIR__.'/fixtures';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$fixture = $_SERVER['HTTP_X_FIXTURE'] ?? 'complete';

header('Content-Type: application/json');

if ('HEAD' === ($_SERVER['REQUEST_METHOD'] ?? 'GET') && '/' === $path) {
    http_response_code(200);
    exit;
}

if (str_ends_with($path, '/v1/messages/count_tokens')) {
    echo json_encode(['input_tokens' => 42], JSON_THROW_ON_ERROR);
    exit;
}

if (!str_ends_with($path, '/v1/messages')) {
    http_response_code(404);
    echo json_encode([
        'type' => 'error',
        'error' => ['type' => 'not_found_error', 'message' => 'fixture path not found: '.$path],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$decoded = json_decode($rawBody, true);
$wantStream = \is_array($decoded) && !empty($decoded['stream']);

if ('echo' === $fixture) {
    echo json_encode([
        'id' => 'msg_echo',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => 'echo-ok']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        'fixture_received_body' => json_decode($rawBody, true),
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ('error-429' === $fixture) {
    http_response_code(429);
    header('retry-after: 5');
    readfile($fixturesDir.'/error-429.json');
    exit;
}

if ('error-401' === $fixture) {
    http_response_code(401);
    echo json_encode([
        'type' => 'error',
        'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key (fixture)'],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$hasToolResult = false;
$toolResultText = '';
if (\is_array($decoded) && isset($decoded['messages']) && \is_array($decoded['messages'])) {
    foreach ($decoded['messages'] as $msg) {
        if (!\is_array($msg) || !isset($msg['content']) || !\is_array($msg['content'])) {
            continue;
        }
        foreach ($msg['content'] as $block) {
            if (\is_array($block) && 'tool_result' === ($block['type'] ?? '')) {
                $hasToolResult = true;
                $content = $block['content'] ?? '';
                if (\is_string($content)) {
                    $toolResultText = $content;
                } elseif (\is_array($content)) {
                    foreach ($content as $part) {
                        if (\is_array($part) && \is_string($part['text'] ?? null)) {
                            $toolResultText .= $part['text'];
                        }
                    }
                }
                break 2;
            }
        }
    }
}

$pickMcpToolName = static function (?array $body): string {
    if (!\is_array($body) || !isset($body['tools']) || !\is_array($body['tools'])) {
        return 'mcp__1__rag_search';
    }
    foreach ($body['tools'] as $tool) {
        if (\is_array($tool) && isset($tool['name']) && str_starts_with((string) $tool['name'], 'mcp__')) {
            return (string) $tool['name'];
        }
    }

    return 'mcp__1__rag_search';
};

if ('web-search' === $fixture) {
    $emitSse = static function (array $events): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        foreach ($events as $event) {
            echo 'event: '.$event['type']."\n";
            echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
            usleep(80_000);
        }
    };

    $reply = static function (array $content, string $stopReason) use ($wantStream, $emitSse): void {
        if (!$wantStream) {
            echo json_encode([
                'id' => 'msg_fixture_web_search',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => $content,
                'stop_reason' => $stopReason,
                'usage' => ['input_tokens' => 30, 'output_tokens' => 12],
            ], JSON_THROW_ON_ERROR);

            return;
        }

        $events = [[
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_fixture_web_search',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [],
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 30, 'output_tokens' => 0],
            ],
        ]];

        foreach ($content as $index => $block) {
            if ('tool_use' === ($block['type'] ?? '')) {
                $events[] = [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => [
                        'type' => 'tool_use',
                        'id' => $block['id'],
                        'name' => $block['name'],
                        'input' => new stdClass(),
                    ],
                ];
                $events[] = [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => [
                        'type' => 'input_json_delta',
                        'partial_json' => json_encode($block['input'], JSON_THROW_ON_ERROR),
                    ],
                ];
            } else {
                $events[] = [
                    'type' => 'content_block_start',
                    'index' => $index,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ];
                $events[] = [
                    'type' => 'content_block_delta',
                    'index' => $index,
                    'delta' => ['type' => 'text_delta', 'text' => $block['text']],
                ];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $index];
        }

        $events[] = [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
            'usage' => ['output_tokens' => 12],
        ];
        $events[] = ['type' => 'message_stop'];

        $emitSse($events);
    };

    if ($hasToolResult) {
        // Answer with the search output verbatim: proof that Synaplan ran the
        // search and fed the results back into the same turn.
        $reply([['type' => 'text', 'text' => 'ANSWER_FROM_SEARCH: '.$toolResultText]], 'end_turn');
        exit;
    }

    $tools = (\is_array($decoded) && \is_array($decoded['tools'] ?? null)) ? $decoded['tools'] : [];
    $executable = null;
    foreach ($tools as $tool) {
        if (\is_array($tool) && 'web_search' === ($tool['name'] ?? null) && isset($tool['input_schema'])) {
            $executable = $tool;
            break;
        }
    }

    if (null === $executable) {
        // Exactly the failure mode this fixture guards against: the model is
        // offered no runnable search tool and can only answer from training data.
        $reply([['type' => 'text', 'text' => 'NO_WEB_SEARCH_TOOL_OFFERED']], 'end_turn');
        exit;
    }

    $reply([[
        'type' => 'tool_use',
        'id' => 'toolu_fixture_web_search',
        'name' => 'web_search',
        'input' => ['query' => 'synaplan release notes', 'max_results' => 3],
    ]], 'tool_use');
    exit;
}

if ('client-tool' === $fixture) {
    $clientTool = 'get_weather';
    if (\is_array($decoded) && \is_array($decoded['tools'] ?? null)) {
        foreach ($decoded['tools'] as $tool) {
            if (\is_array($tool)
                && \is_string($tool['name'] ?? null)
                && isset($tool['input_schema'])
                && !str_starts_with($tool['name'], 'mcp__')
            ) {
                $clientTool = $tool['name'];
                break;
            }
        }
    }

    if ($hasToolResult) {
        // The client executed the tool and sent the result back — answer with
        // it verbatim so the caller can prove the round-trip closed.
        $body = [
            'id' => 'msg_fixture_client_tool_done',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'ANSWER_FROM_CLIENT_TOOL: '.$toolResultText]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 40, 'output_tokens' => 8],
        ];

        if (!$wantStream) {
            echo json_encode($body, JSON_THROW_ON_ERROR);
            exit;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        $events = [
            ['type' => 'message_start', 'message' => ['id' => $body['id'], 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 40, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $body['content'][0]['text']]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 8]],
            ['type' => 'message_stop'],
        ];
        foreach ($events as $event) {
            echo 'event: '.$event['type']."\n";
            echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
            usleep(80_000);
        }
        exit;
    }

    $toolUse = [
        'type' => 'tool_use',
        'id' => 'toolu_fixture_client',
        'name' => $clientTool,
        'input' => ['location' => 'Bremen'],
    ];

    if (!$wantStream) {
        echo json_encode([
            'id' => 'msg_fixture_client_tool',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [$toolUse],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_fixture_client_tool', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 20, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $toolUse['id'], 'name' => $toolUse['name'], 'input' => new stdClass()]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($toolUse['input'], JSON_THROW_ON_ERROR)]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 15]],
        ['type' => 'message_stop'],
    ];
    foreach ($events as $event) {
        echo 'event: '.$event['type']."\n";
        echo 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n";
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
        usleep(80_000);
    }
    exit;
}

if ('tool-use' === $fixture) {
    if ($hasToolResult) {
        // Second loop iteration — finish.
        if ($wantStream) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            $sse = <<<'SSE'
event: message_start
data: {"type":"message_start","message":{"id":"msg_fixture_final","type":"message","role":"assistant","model":"claude-sonnet-4-6","content":[],"stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":40,"output_tokens":0}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Tool loop complete."}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":6}}

event: message_stop
data: {"type":"message_stop"}

SSE;
            foreach (explode("\n", $sse) as $line) {
                echo $line."\n";
                if ('' === $line) {
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                    usleep(100_000);
                }
            }
            exit;
        }

        echo json_encode([
            'id' => 'msg_fixture_final',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Tool loop complete.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 40, 'output_tokens' => 6],
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    $toolName = $pickMcpToolName(\is_array($decoded) ? $decoded : null);

    if ($wantStream) {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        $start = json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_fixture_1',
                'name' => $toolName,
                'input' => new stdClass(),
            ],
        ], JSON_THROW_ON_ERROR);
        $lines = [
            'event: message_start',
            'data: {"type":"message_start","message":{"id":"msg_fixture_tool","type":"message","role":"assistant","model":"claude-sonnet-4-6","content":[],"stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":20,"output_tokens":0}}}',
            '',
            'event: content_block_start',
            'data: '.$start,
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\\"query\\":\\"test\\"}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: ping',
            'data: {"type":"ping"}',
            '',
            'event: message_delta',
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use","stop_sequence":null},"usage":{"output_tokens":15}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
            '',
        ];
        foreach ($lines as $line) {
            echo $line."\n";
            if ('' === $line) {
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                usleep(150_000);
            }
        }
        exit;
    }

    echo json_encode([
        'id' => 'msg_fixture_tool_ns',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-4-6',
        'content' => [[
            'type' => 'tool_use',
            'id' => 'toolu_fixture_1',
            'name' => $toolName,
            'input' => ['query' => 'test'],
        ]],
        'stop_reason' => 'tool_use',
        'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($wantStream || 'stream' === $fixture) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');

    $ssePath = $fixturesDir.'/stream.sse';
    $lines = file($ssePath, FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        http_response_code(500);
        exit;
    }

    foreach ($lines as $line) {
        echo $line."\n";
        if ('' === $line) {
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
            usleep(200_000);
        }
    }
    exit;
}

readfile($fixturesDir.'/complete.json');
