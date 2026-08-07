<?php

declare(strict_types=1);

/**
 * Minimal Anthropic-shaped upstream for Messages gateway smoke tests.
 *
 * Usage:
 *   php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
 *
 * Select a transcript with the X-Fixture request header:
 *   complete (default) | stream | tool-use | error-429 | error-401 | echo
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

$decoded = json_decode($rawBody, true);
$wantStream = \is_array($decoded) && !empty($decoded['stream']);

if ($wantStream || 'stream' === $fixture || 'tool-use' === $fixture) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');

    $file = 'tool-use' === $fixture ? 'stream-tool-use.sse' : 'stream.sse';
    $ssePath = $fixturesDir.'/'.$file;
    if (!is_file($ssePath)) {
        $ssePath = $fixturesDir.'/stream.sse';
    }

    $lines = file($ssePath, FILE_IGNORE_NEW_LINES);
    if (false === $lines) {
        http_response_code(500);
        exit;
    }

    foreach ($lines as $line) {
        echo $line."\n";
        if ('' === $line) {
            // End of an SSE event — pause so smoke scripts can detect incremental delivery.
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
