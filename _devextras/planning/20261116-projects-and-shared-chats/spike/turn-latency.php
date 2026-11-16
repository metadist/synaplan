<?php

declare(strict_types=1);

/**
 * PJ2 spike, not product code. Sends the `simple` turns of the chat-loop
 * corpus and prints one JSON line per turn: seconds to the first frame, to
 * the first text and to the end. Run it once with the turn in the request
 * and once with the turn queued for the pump, and compare.
 *
 * Runs inside the backend container, read from stdin:
 *
 *   docker compose exec -T -e CHAT_LOOP_EVAL_PASSWORD backend \
 *     php -- --email=<account> --label=request --passes=3 < turn-latency.php
 *
 * The password comes from CHAT_LOOP_EVAL_PASSWORD only.
 */

use App\Tests\Eval\ChatLoop\ChatLoopCorpus;
use App\Tests\Eval\ChatLoop\StackClient;
use App\Tests\Eval\ChatLoop\TurnMeter;
use Symfony\Component\HttpClient\HttpClient;

require '/var/www/backend/vendor/autoload.php';

$options = getopt('', ['email:', 'label:', 'passes::', 'base-url::', 'group::', 'only::']);
$email = (string) ($options['email'] ?? '');
$label = (string) ($options['label'] ?? '');
$passes = max(1, (int) ($options['passes'] ?? 1));
$group = (string) ($options['group'] ?? 'simple');
$only = (string) ($options['only'] ?? '');
$password = getenv('CHAT_LOOP_EVAL_PASSWORD');
if ('' === $email || '' === $label || !is_string($password) || '' === $password) {
    fwrite(STDERR, "Needs --email, --label and CHAT_LOOP_EVAL_PASSWORD.\n");
    exit(2);
}

$turns = array_values(array_filter(
    (new ChatLoopCorpus())->load(),
    static fn ($turn): bool => $group === $turn->group && ('' === $only || $only === $turn->id),
));

$stack = new StackClient(HttpClient::create(), (string) ($options['base-url'] ?? 'http://localhost'));
$stack->signIn($email, $password);
try {
    for ($pass = 1; $pass <= $passes; ++$pass) {
        foreach ($turns as $turn) {
            $chatId = $stack->createChat('pj2-spike '.$turn->id);
            try {
                $started = microtime(true);
                $meter = new TurnMeter($turn, $started);
                $firstFrame = null;
                foreach ($stack->turn($chatId, $turn, [], 120.0) as $event) {
                    $now = microtime(true);
                    $firstFrame ??= $now - $started;
                    $meter->event($event, $now);
                }
                $outcome = $meter->outcome(microtime(true));
                echo json_encode([
                    'label' => $label,
                    'pass' => $pass,
                    'id' => $turn->id,
                    'state' => $outcome->state,
                    'reason' => $outcome->reason,
                    'first_frame' => null === $firstFrame ? null : round($firstFrame, 3),
                    'first_token' => null === $outcome->firstTokenSeconds ? null : round($outcome->firstTokenSeconds, 3),
                    'total' => round($outcome->totalSeconds, 3),
                    'cost' => round($outcome->cost, 6),
                ], JSON_THROW_ON_ERROR)."\n";
            } finally {
                $stack->deleteChat($chatId);
            }
        }
    }
} finally {
    $stack->signOut();
}
