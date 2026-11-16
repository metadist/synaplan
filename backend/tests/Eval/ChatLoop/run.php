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

$options = getopt('', ['base-url:', 'email:', 'group:', 'only:', 'script:', 'report:', 'answers:', 'turn-timeout:', 'live', 'keep-chats']);
$fail = static function (string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
};
$option = static fn (string $name, ?string $default = null): ?string => \is_string($options[$name] ?? null) && '' !== trim($options[$name]) ? $options[$name] : $default;

$email = $option('email') ?? $fail('Pass --email with the evaluation account. Nothing was sent.');
$password = getenv(PASSWORD_ENV);
if (!\is_string($password) || '' === $password) {
    $fail('Set '.PASSWORD_ENV.' to the password of the evaluation account. An option would stay in the shell history. Nothing was sent.');
}

try {
    $turns = selectTurns((new ChatLoopCorpus())->load(), $option('group'), $option('only'));
    $script = $option('script');
    $scriptTurns = null === $script ? [] : ScriptedChats::conversation(ScriptedChats::DIR.'/'.basename($script, '.json').'.json');

    $stack = new StackClient(HttpClient::create(), $option('base-url', 'http://localhost:8000'));
    $stack->signIn($email, $password);
    SpendGuard::check($stack->defaultModels(), isset($options['live']));

    $runner = new CorpusRunner(
        $stack,
        max(1.0, (float) $option('turn-timeout', (string) DEFAULT_TURN_TIMEOUT)),
        progress: static function (TurnOutcome $outcome): void {
            fwrite(STDERR, sprintf("%-14s %-10s %-9s %7.2f s  %s\n", $outcome->id, $outcome->group, $outcome->state, $outcome->totalSeconds, TurnOutcome::STATE_ANSWERED === $outcome->state ? '' : (string) $outcome->reason));
        },
        keepChats: isset($options['keep-chats']),
    );

    $outcomes = null === $script ? $runner->run($turns) : $runner->runAsOneChat((string) $script, $scriptTurns);

    $commandLine = 'php tests/Eval/ChatLoop/run.php '.implode(' ', array_map(
        static fn (string $name): string => \is_string($options[$name]) ? '--'.$name.'='.$options[$name] : '--'.$name,
        array_keys($options),
    ));
    $report = GroupReport::markdown($outcomes, $commandLine);
    writeFile($option('report'), $report);
    writeFile($option('answers'), json_encode(array_map(
        static fn (TurnOutcome $o): array => ['id' => $o->id, 'arm' => 'chat-today', 'run' => 1, 'text' => $o->text, 'evidence' => new stdClass()],
        array_values(array_filter($outcomes, static fn (TurnOutcome $o): bool => TurnOutcome::STATE_ANSWERED === $o->state)),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo $report;

    CorpusRunner::requireTerminalResults($outcomes);
} catch (Throwable $e) {
    $fail($e->getMessage());
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
