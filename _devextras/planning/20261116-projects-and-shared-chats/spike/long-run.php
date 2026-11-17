<?php

declare(strict_types=1);

/**
 * PJ2 spike, not product code. A long run on a model with a small window.
 *
 * One goal, read one section at a time with a stub tool, so the transcript
 * outgrows the window many times. When the estimate reaches the trigger, the
 * transcript is replaced by notes (contract 03 §1.5: the system prompt, the
 * person's goal and the last steps stay word for word; the rest becomes
 * notes, one call on the same model). Every step reloads its state from a
 * checkpoint file, as a run unit does.
 *
 * The answer has a known gold value, so the run is scored without a model:
 * the goal is kept when the total and the top section are right, a fact
 * survives when the code word of section 3 is quoted, and duplicate work is
 * a section read again.
 *
 *   docker compose exec -T -e CHAT_LOOP_SPIKE_BUDGET=1.00 backend \
 *     php -- --label=fast --pause=0 < long-run.php
 *
 * The provider key is read from the container's environment and never
 * printed. Cost is counted here from the reported tokens at the catalog
 * price of the model (0.15 in, 0.60 out per million) and stops the run at
 * `--max-cost`.
 */

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

require '/var/www/backend/vendor/autoload.php';

const MODEL = 'openai/gpt-oss-120b';
const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
const PRICE_IN = 0.15;
const PRICE_OUT = 0.60;
const SUPPLIERS = ['Nordholz', 'Kuestenbau', 'Alpenstahl', 'Weserwerk', 'Fichtner'];
const CODE_WORD = 'FJORD-77';

$o = getopt('', ['label::', 'sections::', 'window::', 'compact-at::', 'keep::', 'pause::', 'max-cost::', 'max-steps::', 'seed::', 'guard-finish::', 'contract::']);
$label = (string) ($o['label'] ?? 'run');
$sections = (int) ($o['sections'] ?? 90);
$window = (int) ($o['window'] ?? 8000);
$compactAt = (int) ($o['compact-at'] ?? 70);
$keepSteps = (int) ($o['keep'] ?? 4);
$pause = (int) ($o['pause'] ?? 0);
$maxCost = (float) ($o['max-cost'] ?? 1.00);
$maxSteps = (int) ($o['max-steps'] ?? 260);
$seed = (int) ($o['seed'] ?? 7);
$guardFinish = (bool) (int) ($o['guard-finish'] ?? 0);
// 3: as 2, plus named values the loop keeps verbatim (tool `note`), a ledger
// of read sections the loop writes into every prompt, and a forced `finish`
// once the work list is empty. 1: the first prototype. 2: the changes the first run called for (short plan,
// running values as facts, summary size check, repeat calls from the ledger).
$contract = (int) ($o['contract'] ?? 1);
$key = getenv('GROQ_API_KEY');
if (!is_string($key) || '' === $key) {
    fwrite(STDERR, "No provider key in the environment. Nothing was sent.\n");
    exit(2);
}

// ---- the document and its gold values -------------------------------------
mt_srand($seed);
$words = ['joint', 'panel', 'plinth', 'drawer', 'hinge', 'veneer', 'worktop', 'cabinet', 'finish', 'tolerance', 'delivery', 'batch', 'carrier', 'batten', 'pallet', 'oak', 'beech', 'walnut', 'adhesive', 'clamp'];
$doc = [];
$used = [];
for ($n = 1; $n <= $sections; ++$n) {
    do {
        $amount = mt_rand(1000, 99999);
    } while (isset($used[$amount]));
    $used[$amount] = true;
    $supplier = SUPPLIERS[mt_rand(0, \count(SUPPLIERS) - 1)];
    $filler = [];
    for ($i = 0; $i < 170; ++$i) {
        $filler[] = $words[mt_rand(0, \count($words) - 1)];
    }
    $text = "Section $n\nSupplier: $supplier\nAmount: $amount EUR\n".(3 === $n ? 'Code word: '.CODE_WORD."\n" : '').'Notes: '.implode(' ', $filler);
    $doc[$n] = ['supplier' => $supplier, 'amount' => $amount, 'text' => $text];
}
$goldTotal = array_sum(array_map(static fn (array $s): int => 'Nordholz' === $s['supplier'] ? $s['amount'] : 0, $doc));
$goldTop = array_search(max(array_column($doc, 'amount')), array_column($doc, 'amount'), true) + 1;

// ---- model calls -----------------------------------------------------------
$http = HttpClient::create();
$cost = 0.0;
$calls = 0;
$started = microtime(true);

$emit = static function (array $row) use (&$cost, $label, $started): void {
    echo json_encode(['label' => $label, 't' => round(microtime(true) - $started, 1), 'cost' => round($cost, 5)] + $row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
};

$call = static function (array $body) use ($http, $key, &$cost, &$calls, $emit): ?array {
    for ($try = 1; $try <= 4; ++$try) {
        try {
            $response = $http->request('POST', ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer '.$key],
                'json' => $body + ['model' => MODEL],
                'timeout' => 90,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $e) {
            $status = 0;
            $data = null;
        }
        if (200 === $status && \is_array($data)) {
            ++$calls;
            $usage = $data['usage'] ?? [];
            $in = (int) ($usage['prompt_tokens'] ?? 0);
            $out = (int) ($usage['completion_tokens'] ?? 0);
            $cost += $in * PRICE_IN / 1e6 + $out * PRICE_OUT / 1e6;

            return ['message' => $data['choices'][0]['message'] ?? [], 'in' => $in, 'out' => $out, 'finish' => $data['choices'][0]['finish_reason'] ?? null];
        }
        $emit(['event' => 'retry', 'try' => $try, 'http' => $status, 'error' => mb_substr((string) ($data['error']['message'] ?? ''), 0, 260), 'code' => $data['error']['code'] ?? null]);
        sleep(5 * $try);
    }

    return null;
};

$estimate = static fn (array $messages): int => (int) ceil(mb_strlen(json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '') / 4);

// ---- the run ---------------------------------------------------------------
$system = 'You work through a long document one section at a time with the tool read_section. Your working memory is small. A message that starts with NOTES is your own summary of earlier work: trust it, and never read a section again that the notes say you read. When every section is read, call finish with the final answer.';
if (3 === $contract) {
    $system .= ' After you read a section, call record(section, supplier, amount, code_word if the section prints one) before the next read. The system keeps the ledger of recorded sections and the totals, and shows them in every prompt as VALUES; you do not add anything yourself. When all sections are read and recorded, call finish with the answer from VALUES.';
}
$goal = "Read sections 1 to $sections, one at a time, in order. Then call finish with: (1) total_nordholz, the sum of the amounts of all sections whose supplier is Nordholz; (2) top_section, the number of the section with the highest amount; (3) code_word, the code word printed in section 3.";
$tools = [
    ['type' => 'function', 'function' => ['name' => 'read_section', 'description' => 'Returns the text of one section.', 'parameters' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']]]],
    ['type' => 'function', 'function' => ['name' => 'note', 'description' => 'Stores a named value; it replaces the old value of that name.', 'parameters' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['name', 'value']]]],
    ['type' => 'function', 'function' => ['name' => 'record', 'description' => 'Records the figures of one section you just read in the system ledger. The system keeps the totals.', 'parameters' => ['type' => 'object', 'properties' => ['section' => ['type' => 'integer'], 'supplier' => ['type' => 'string'], 'amount' => ['type' => 'integer'], 'code_word' => ['type' => 'string']], 'required' => ['section', 'supplier', 'amount']]]],
    ['type' => 'function', 'function' => ['name' => 'finish', 'description' => 'Ends the run with the final answer.', 'parameters' => ['type' => 'object', 'properties' => ['total_nordholz' => ['type' => 'integer'], 'top_section' => ['type' => 'integer'], 'code_word' => ['type' => 'string']], 'required' => ['total_nordholz', 'top_section', 'code_word']]]],
];
$checkpoint = sys_get_temp_dir().'/pj2-'.$label.'.json';
$state = ['notes' => null, 'steps' => [], 'read' => [], 'compactions' => 0, 'dup' => 0, 'answer' => null, 'end' => null, 'maxPrompt' => 0, 'nudges' => 0, 'rejected' => 0, 'values' => [], 'postRead' => 0, 'rows' => [], 'pending' => null, 'pendingRejected' => 0];
file_put_contents($checkpoint, json_encode($state));

$build = static function (array $state) use ($system, $goal): array {
    $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $goal]];
    if (null !== $state['notes']) {
        $messages[] = ['role' => 'user', 'content' => "NOTES (your summary of earlier work):\n".json_encode($state['notes'], JSON_UNESCAPED_UNICODE)];
    }
    if (3 === $GLOBALS['contract']) {
        $read = array_keys($state['read']);
        sort($read);
        $ranges = [];
        foreach ($read as $n) {
            $last = \count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] === $n - 1) {
                $ranges[$last][1] = $n;
            } else {
                $ranges[] = [$n, $n];
            }
        }
        $totals = [];
        $top = null;
        $code = null;
        foreach ($state['rows'] as $sec => $row) {
            $totals[$row['supplier']] = ($totals[$row['supplier']] ?? 0) + $row['amount'];
            if (null === $top || $row['amount'] > $top['amount']) {
                $top = ['section' => $sec, 'amount' => $row['amount']];
            }
            if ('' !== $row['code']) {
                $code = $row['code'];
            }
        }
        $values = ['recorded_sections' => \count($state['rows']), 'totals_by_supplier' => (object) $totals, 'highest_amount' => $top, 'code_word' => $code];
        $ledger = implode(', ', array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0].'-'.$r[1], $ranges));
        $messages[] = ['role' => 'user', 'content' => "LEDGER (kept by the system): sections read: ".('' === $ledger ? 'none' : $ledger).".\nVALUES (kept by the system, exact): ".json_encode($values, JSON_UNESCAPED_UNICODE)];
    }
    foreach ($state['steps'] as $step) {
        foreach ($step as $message) {
            $messages[] = $message;
        }
    }

    return $messages;
};

for ($i = 0; $i < $maxSteps; ++$i) {
    $state = json_decode((string) file_get_contents($checkpoint), true, 512, JSON_THROW_ON_ERROR);
    if ($cost >= $maxCost) {
        $state['end'] = 'cost limit';
        break;
    }
    $messages = $build($state);

    if ($estimate($messages) >= $window * $compactAt / 100 && \count($state['steps']) > $keepSteps) {
        $old = \array_slice($state['steps'], 0, \count($state['steps']) - $keepSteps);
        $transcript = [];
        foreach ($old as $step) {
            foreach ($step as $message) {
                $transcript[] = $message;
            }
        }
        $result = $call([
            'messages' => [
                ['role' => 'system', 'content' => 3 === $contract
                    ? 'You compact the work of an agent into notes so it can continue with a small memory. Reply with one JSON object only: {"goal": string, "plan": [{"text": string, "state": "doing|open"}], "decisions": [string], "open": [string], "facts": [{"text": string, "ref": string}]}. Rules: the plan has at most 5 items and lists only what is still to do; the system keeps the running values and the list of read sections itself, so do not put sums, maxima or section lists in the notes; facts holds only other things that must survive. Keep the whole object under 1000 characters.'
                    : (2 === $contract
                    ? 'You compact the work of an agent into notes so it can continue with a small memory. Reply with one JSON object only: {"goal": string, "progress": string, "plan": [{"text": string, "state": "doing|open"}], "facts": [{"text": string, "ref": string}], "decisions": [string], "open": [string]}. Rules: the plan has at most 5 items and lists only what is still to do, never one item per section; progress is one short line such as "sections 1-37 read"; facts holds the running results the goal needs as single values (for example the sum so far of the amounts of one supplier, the highest amount so far with its section number, a code word), and you update each value, you do not list rows to add up again. Do not copy page text. Keep the whole object under 1500 characters.'
                    : 'You compact the work of an agent into notes so it can continue with a small memory. Reply with one JSON object only: {"goal": string, "plan": [{"text": string, "state": "done|doing|open"}], "facts": [{"text": string, "ref": string}], "decisions": [string], "open": [string]}. Keep every exact figure, name and number the goal needs, and which sections were read. A ref names the section a fact came from. Do not copy page text.'),],
                ['role' => 'user', 'content' => "GOAL:\n$goal\n\nNOTES SO FAR:\n".json_encode($state['notes'], JSON_UNESCAPED_UNICODE)."\n\nTRANSCRIPT TO REPLACE:\n".json_encode($transcript, JSON_UNESCAPED_UNICODE)],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_completion_tokens' => $contract >= 2 ? 1200 : 2500,
            'reasoning_effort' => 'low',
        ]);
        $notes = null === $result ? null : json_decode((string) ($result['message']['content'] ?? ''), true);
        if ($contract >= 2 && null !== $result && $result['out'] >= 1200) {
            $notes = null; // a summary that hits its budget is a failed compaction
        }
        if (!\is_array($notes) || !isset($notes['goal'], $notes['facts']) || !\is_array($notes['facts'])) {
            $state['end'] = 'compaction failed';
            $emit(['event' => 'compaction_failed']);
            break;
        }
        $state['notes'] = $notes;
        $state['steps'] = \array_slice($state['steps'], -$keepSteps);
        ++$state['compactions'];
        $messages = $build($state);
        $emit(['event' => 'compaction', 'n' => $state['compactions'], 'in' => $result['in'], 'out' => $result['out'], 'notes_chars' => mb_strlen(json_encode($notes)), 'estimate_after' => $estimate($messages), 'notes' => mb_substr(json_encode($notes, JSON_UNESCAPED_UNICODE), 0, 1800)]);
        file_put_contents($checkpoint, json_encode($state));
    }

    $toolChoice = 'auto';
    $offered = $tools;
    if (3 === $contract && \count($state['read']) === $sections) {
        ++$state['postRead'];
        if (($state['postRead'] >= 3 && null === $state['pending']) || $state['postRead'] >= 10) {
            // the work list is empty: ask for the answer instead of waiting for it
            $toolChoice = 'required';
            $offered = [$tools[3]]; // only `finish` is offered
            $emit(['event' => 'finish_forced']);
        }
    }
    $result = $call(['messages' => $messages, 'tools' => $offered, 'tool_choice' => $toolChoice, 'parallel_tool_calls' => false, 'max_completion_tokens' => 1200, 'reasoning_effort' => 'low']);
    if (null === $result && 'auto' !== $toolChoice) {
        // the tool call came back malformed: ask for the answer as plain JSON instead
        $emit(['event' => 'finish_fallback_json']);
        $fallback = $call(['messages' => [...$messages, ['role' => 'user', 'content' => 'Reply with one JSON object only: {"total_nordholz": integer, "top_section": integer, "code_word": string}, from the VALUES.']], 'response_format' => ['type' => 'json_object'], 'max_completion_tokens' => 400, 'reasoning_effort' => 'low']);
        $answer = null === $fallback ? null : json_decode((string) ($fallback['message']['content'] ?? ''), true);
        if (\is_array($answer)) {
            $state['answer'] = $answer;
            $state['end'] = 'finished';
            $emit(['event' => 'finish_called', 'sections_read' => \count($state['read']), 'args' => $answer, 'via' => 'json']);
            break;
        }
    }
    if (null === $result) {
        $state['end'] = 'provider unavailable';
        break;
    }
    $state['maxPrompt'] = max($state['maxPrompt'], $result['in']);
    $assistant = ['role' => 'assistant', 'content' => $result['message']['content'] ?? null];
    $calls_ = $result['message']['tool_calls'] ?? [];
    $step = [];
    if ([] === $calls_) {
        ++$state['nudges'];
        $emit(['event' => 'no_tool', 'in' => $result['in'], 'said' => mb_substr((string) ($result['message']['content'] ?? ''), 0, 240), 'finish' => $result['finish'], 'out' => $result['out']]);
        if ($state['nudges'] > 5) {
            $state['end'] = 'no tool call';
            break;
        }
        $unread = [];
        for ($k = 1; $k <= $sections; ++$k) {
            if (!isset($state['read'][$k])) {
                $unread[] = $k;
            }
        }
        $hint = 3 === $contract && [] !== $unread ? ' Next unread section: '.$unread[0].'.' : '';
        $step = [$assistant, ['role' => 'user', 'content' => 'Continue with the tool.'.$hint]];
    } else {
        $state['nudges'] = 0;
        $assistant['tool_calls'] = $calls_;
        $step[] = $assistant;
        foreach ($calls_ as $tc) {
            $args = json_decode((string) ($tc['function']['arguments'] ?? ''), true);
            $args = \is_array($args) ? $args : [];
            $name = $tc['function']['name'] ?? '';
            if ('finish' === $name && $guardFinish && \count($state['read']) < $sections && $state['rejected'] < 12) {
                ++$state['rejected'];
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'error: not finished. Sections read so far: '.\count($state['read']).' of '.$sections.'. Continue with the next unread section.'];
                $emit(['event' => 'finish_rejected', 'sections_read' => \count($state['read'])]);
                continue;
            }
            if ('finish' === $name) {
                $state['answer'] = $args;
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'done'];
                $state['end'] = 'finished';
                $emit(['event' => 'finish_called', 'sections_read' => \count($state['read']), 'args' => $args]);
                break;
            }
            if ('record' === $name && 3 === $contract) {
                $sec = (int) ($args['section'] ?? 0);
                $state['rows'][$sec] = ['supplier' => (string) ($args['supplier'] ?? ''), 'amount' => (int) ($args['amount'] ?? 0), 'code' => (string) ($args['code_word'] ?? '')];
                $state['pending'] = null;
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'recorded section '.$sec];
                $emit(['event' => 'record', 'section' => $sec]);
                continue;
            }
            if ('note' === $name && 3 === $contract) {
                $state['values'][(string) ($args['name'] ?? '?')] = (string) ($args['value'] ?? '');
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'noted'];
                $emit(['event' => 'note', 'name' => $args['name'] ?? null, 'value' => $args['value'] ?? null]);
                continue;
            }
            $n = (int) ($args['n'] ?? 0);
            if ('read_section' === $name && 3 === $contract && null !== $state['pending'] && $state['pendingRejected'] < 40) {
                ++$state['pendingRejected'];
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'error: record section '.$state['pending'].' first with record(section, supplier, amount).'];
                $emit(['event' => 'read_rejected', 'pending' => $state['pending']]);
                continue;
            }
            if ('read_section' === $name && isset($doc[$n])) {
                if (isset($state['read'][$n])) {
                    ++$state['dup'];
                    if ($contract >= 2) {
                        $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'already read; its figures are in your notes. Continue with the next unread section.'];
                        $emit(['event' => 'repeat_answered', 'n' => $n]);
                        continue;
                    }
                }
                $state['read'][$n] = true;
                $state['pending'] = $n;
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => $doc[$n]['text']];
                $emit(['event' => 'read', 'n' => $n, 'in' => $result['in'], 'out' => $result['out'], 'dup' => isset($state['read'][$n]) && $state['dup'] > 0 ? $state['dup'] : 0]);
            } else {
                $step[] = ['role' => 'tool', 'tool_call_id' => $tc['id'], 'content' => 'error: unknown call'];
                $emit(['event' => 'bad_call', 'name' => $name]);
            }
        }
    }
    $state['steps'][] = $step;
    file_put_contents($checkpoint, json_encode($state));
    if ('finished' === $state['end']) {
        break;
    }
    if ($pause > 0) {
        sleep($pause);
    }
}
$state['end'] ??= 'step limit';

$answer = \is_array($state['answer']) ? $state['answer'] : [];
$goalKept = (int) ($answer['total_nordholz'] ?? -1) === $goldTotal && (int) ($answer['top_section'] ?? -1) === $goldTop;
$emit([
    'event' => 'summary',
    'end' => $state['end'],
    'calls' => $calls,
    'compactions' => $state['compactions'],
    'sections_read' => \count($state['read']),
    'duplicate_reads' => $state['dup'],
    'contract' => $contract,
    'values' => $state['values'],
    'rows_recorded' => \count($state['rows']),
    'pending_rejected' => $state['pendingRejected'],
    'finish_rejected' => $state['rejected'],
    'complete_read' => \count($state['read']) === $sections,
    'goal_kept' => $goalKept,
    'total_ok' => (int) ($answer['total_nordholz'] ?? -1) === $goldTotal,
    'top_ok' => (int) ($answer['top_section'] ?? -1) === $goldTop,
    'code_word_ok' => false !== stripos((string) ($answer['code_word'] ?? ''), CODE_WORD),
    'max_prompt_tokens' => $state['maxPrompt'],
    'window' => $window,
    'gold' => ['total' => $goldTotal, 'top' => $goldTop],
    'answer' => $answer,
]);
@unlink($checkpoint);
