<?php

declare(strict_types=1);

/*
 * Runs the chat-loop corpus (roadmap row 46, PJ1) against a stack and writes
 * cost, time to first token, total time, recall and backed statements per
 * group. Usage and options: see README.md in this directory.
 */

use App\Tests\Eval\ChatLoop\ChatLoopCorpus;
use App\Tests\Eval\ChatLoop\CorpusRunner;
use App\Tests\Eval\ChatLoop\CorpusTurn;
use App\Tests\Eval\ChatLoop\GroupReport;
use App\Tests\Eval\ChatLoop\ScriptedChats;
use App\Tests\Eval\ChatLoop\SpendGuard;
use App\Tests\Eval\ChatLoop\StackClient;
use App\Tests\Eval\ChatLoop\TurnOutcome;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__, 3).'/vendor/autoload.php';

const PASSWORD_ENV = 'CHAT_LOOP_EVAL_PASSWORD';
const DEFAULT_TURN_TIMEOUT = 1800.0;

const OPTIONS = ['base-url:', 'email:', 'group:', 'only:', 'script:', 'report:', 'answers:', 'turn-timeout:', 'live', 'keep-chats'];

$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};

// getopt() drops what it does not know. A mistyped --group would then run
// the whole corpus, so every argument is checked first.
$known = array_map(static fn (string $name): string => rtrim($name, ':'), OPTIONS);
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    $name = str_starts_with($argument, '--') ? explode('=', substr($argument, 2), 2)[0] : null;
    if (null === $name || !in_array($name, $known, true)) {
        $fail('Unknown argument "'.$argument.'". Options: --'.implode(', --', $known).'. Write values as --name=value. Nothing was sent.');
    }
    if (in_array($name.':', OPTIONS, true) && !str_contains($argument, '=')) {
        $fail('--'.$name.' needs a value: --'.$name.'=…. Nothing was sent.');
    }
}
$options = getopt('', OPTIONS);
$option = static fn (string $name, ?string $default = null): ?string => \is_string($options[$name] ?? null) && '' !== trim($options[$name]) ? $options[$name] : $default;

$email = $option('email') ?? $fail('Pass --email with the evaluation account. Nothing was sent.');
$password = getenv(PASSWORD_ENV);
if (!\is_string($password) || '' === $password) {
    $fail('Set '.PASSWORD_ENV.' to the password of the evaluation account. An option would stay in the shell history. Nothing was sent.');
}
$script = $option('script');
if (null !== $script && (null !== $option('group') || null !== $option('only'))) {
    $fail('--script runs one scripted chat; --group and --only choose corpus turns. Pass one or the other. Nothing was sent.');
}
$timeout = $option('turn-timeout', (string) DEFAULT_TURN_TIMEOUT);
if (!is_numeric($timeout) || (float) $timeout < 1.0) {
    $fail('--turn-timeout is a number of seconds, at least 1. Nothing was sent.');
}
$live = isset($options['live']);

$stack = null;
$failure = null;
try {
    $turns = null === $script
        ? selectTurns((new ChatLoopCorpus())->load(), $option('group'), $option('only'))
        : ScriptedChats::conversation(ScriptedChats::DIR.'/'.basename($script, '.json').'.json');

    $stack = new StackClient(HttpClient::create(), $option('base-url', 'http://localhost:8000'));
    $stack->signIn($email, $password);
    SpendGuard::check($stack->defaultModels(), $live, null === $script ? SpendGuard::CORPUS : SpendGuard::SCRIPT);

    $runner = new CorpusRunner(
        $stack,
        (float) $timeout,
        progress: static function (TurnOutcome $outcome): void {
            fwrite(STDERR, sprintf("%-14s %-10s %-9s %7.2f s  %s\n", $outcome->id, $outcome->group, $outcome->state, $outcome->totalSeconds, TurnOutcome::STATE_ANSWERED === $outcome->state ? '' : (string) $outcome->reason));
        },
        keepChats: isset($options['keep-chats']),
        live: $live,
    );

    $outcomes = null === $script ? $runner->run($turns) : $runner->runAsOneChat($script, $turns);

    // The account's address stays out of a report that is pasted elsewhere.
    $commandLine = 'php tests/Eval/ChatLoop/run.php '.implode(' ', array_map(
        static fn (string $name): string => match (true) {
            'email' === $name => '--email=<account>',
            \is_string($options[$name]) => '--'.$name.'='.$options[$name],
            default => '--'.$name,
        },
        array_keys($options),
    ));
    $report = GroupReport::markdown($outcomes, $commandLine);
    writeFile($option('report'), $report);
    writeFile($option('answers'), json_encode(array_map(
        static fn (TurnOutcome $o): array => ['id' => $o->id, 'arm' => 'chat-today', 'run' => 1, 'text' => $o->text, 'evidence' => new stdClass()],
        array_values(array_filter($outcomes, static fn (TurnOutcome $o): bool => TurnOutcome::STATE_ANSWERED === $o->state)),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo $report;

    foreach ($runner->leftovers() as $leftover) {
        fwrite(STDERR, 'Left in the account, remove by hand: '.$leftover."\n");
    }
    if (null !== $runner->spendStop()) {
        throw new RuntimeException('Stopped after "'.$runner->spendStop().'": the account was charged although --live was not passed. A planning, tool or search call outside the test models did that. The numbers of this run do not count.');
    }
    CorpusRunner::requireTerminalResults($outcomes);
} catch (Throwable $e) {
    $failure = $e->getMessage();
} finally {
    $stack?->signOut();
}
if (null !== $failure) {
    $fail($failure);
}

/**
 * @param list<CorpusTurn> $turns
 *
 * @return list<CorpusTurn>
 */
function selectTurns(array $turns, ?string $group, ?string $only): array
{
    if (null !== $group && !in_array($group, ChatLoopCorpus::GROUPS, true)) {
        throw new RuntimeException('Unknown group "'.$group.'". Groups: '.implode(', ', ChatLoopCorpus::GROUPS).'.');
    }
    $ids = null === $only ? null : array_map('trim', explode(',', $only));
    $selected = array_values(array_filter(
        $turns,
        static fn (CorpusTurn $turn): bool => (null === $group || $turn->group === $group) && (null === $ids || in_array($turn->id, $ids, true)),
    ));
    if ([] === $selected) {
        throw new RuntimeException('No turn matches --group / --only. Nothing was sent.');
    }

    return $selected;
}

function writeFile(?string $path, string $content): void
{
    if (null === $path) {
        return;
    }
    $dir = dirname($path);
    if ((!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) || false === file_put_contents($path, $content)) {
        throw new RuntimeException('Could not write '.$path.'.');
    }
}
