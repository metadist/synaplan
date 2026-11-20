<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunIds;
use App\Service\Run\RunState;
use App\Service\Run\RunStateCodec;

/**
 * Reads and writes the stored form of a chat run. A form that is not a chat
 * run is refused; a broken chat run counts as no run.
 */
final readonly class ChatRunCodec implements RunStateCodec
{
    private const KIND = 'chat';
    private const VERSION = 1;

    public static function encode(ChatRunState $state, int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): string
    {
        return json_encode([
            'kind' => self::KIND,
            'v' => self::VERSION,
            'runId' => $state->runId,
            'userId' => $state->userId,
            'chatId' => $state->chatId,
            'incomingMessageId' => $state->incomingMessageId,
            'replyMessageId' => $state->replyMessageId,
            'provider' => $state->provider,
            'model' => $state->model,
            'locale' => $state->locale,
            'basePrompt' => $state->basePrompt,
            'messages' => $state->messages,
            'pending' => $state->pending,
            'plan' => $state->plan,
            'reads' => (object) $state->reads,
            'opened' => $state->opened,
            'status' => $state->status->value,
            'end' => $state->end?->value,
            'waitReason' => $state->waitReason,
            'answer' => $state->answer,
            'said' => $state->said,
            'note' => $state->note,
            'mode' => $state->mode,
            'steps' => $state->steps,
            'costMicro' => $state->costMicro,
            'nudges' => $state->nudges,
            'modelFailures' => $state->modelFailures,
            'dearestMicro' => $state->dearestMicro,
            'wrapUp' => $state->wrapUp?->value,
            'stall' => ChatRunStallDetector::encode($state->stall),
            'expected' => $expectedSeq,
            'checkpoint' => $checkpointSeq,
            'updated' => $now,
            'expiresAt' => $expiresAt,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function decode(string $stored): ?RunState
    {
        try {
            // The default depth, as on the way in: tool arguments nest as deep as the model writes them.
            $data = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($data)) {
            return null;
        }
        if (self::KIND !== ($data['kind'] ?? null)) {
            throw new \UnexpectedValueException('This stored run is not a chat run.');
        }

        return self::state($data);
    }

    /**
     * @param array<mixed> $data
     */
    private static function state(array $data): ?ChatRunState
    {
        $runId = $data['runId'] ?? null;
        $status = ChatRunStatus::tryFrom((string) ($data['status'] ?? ''));
        if (!\is_string($runId) || 1 !== preg_match(RunIds::PATTERN, $runId) || null === $status
            || !self::positiveInt($data['userId'] ?? null) || !self::positiveInt($data['chatId'] ?? null)
            || !\is_array($data['messages'] ?? null) || !array_is_list($data['messages'])
            || !\is_string($data['basePrompt'] ?? null) || !\is_string($data['locale'] ?? null)) {
            return null;
        }
        $incoming = $data['incomingMessageId'] ?? null;
        $state = new ChatRunState(
            $runId,
            (int) $data['userId'],
            (int) $data['chatId'],
            self::positiveInt($incoming) ? (int) $incoming : null,
            self::optionalString($data['provider'] ?? null),
            self::optionalString($data['model'] ?? null),
            $data['locale'],
            $data['basePrompt'],
            $data['messages'],
            (int) ($data['updated'] ?? 0),
        );
        $state->status = $status;
        $reply = $data['replyMessageId'] ?? null;
        $state->replyMessageId = self::positiveInt($reply) ? (int) $reply : null;
        $state->end = ChatRunEnd::tryFrom((string) ($data['end'] ?? ''));
        $state->waitReason = self::optionalString($data['waitReason'] ?? null);
        $state->answer = self::optionalString($data['answer'] ?? null);
        $state->said = \is_string($data['said'] ?? null) ? ChatRunSpeaker::cap($data['said']) : '';
        $state->note = \is_string($data['note'] ?? null) ? ChatRunSpeaker::cap($data['note']) : null;
        $mode = $data['mode'] ?? ChatRunState::MODE_FREE;
        $state->mode = \in_array($mode, ChatRunState::MODES, true) ? $mode : ChatRunState::MODE_FREE;
        $state->steps = max(0, (int) ($data['steps'] ?? 0));
        $state->costMicro = max(0, (int) ($data['costMicro'] ?? 0));
        $state->nudges = max(0, (int) ($data['nudges'] ?? 0));
        $state->modelFailures = max(0, (int) ($data['modelFailures'] ?? 0));
        $state->dearestMicro = max(0, (int) ($data['dearestMicro'] ?? 0));
        $state->wrapUp = ChatRunWrapUp::reason($data['wrapUp'] ?? null);
        $state->stall = ChatRunStallDetector::decode($data['stall'] ?? null);
        $state->pending = self::pending($data['pending'] ?? []);
        $state->plan = self::plan($data['plan'] ?? []);
        $state->reads = self::reads($data['reads'] ?? []);
        $state->opened = self::opened($data['opened'] ?? []);
        $expiresAt = $data['expiresAt'] ?? null;
        $state->restore(
            max(1, (int) ($data['expected'] ?? 1)),
            max(0, (int) ($data['checkpoint'] ?? 0)),
            (int) ($data['updated'] ?? 0),
            \is_int($expiresAt) ? $expiresAt : null,
        );

        return $state;
    }

    /**
     * @return list<array{id: string, name: string, args: array<string, mixed>, approval?: int, decision?: string}>
     */
    private static function pending(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $call) {
            if (!\is_array($call) || !\is_string($call['id'] ?? null) || !\is_string($call['name'] ?? null) || !\is_array($call['args'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $args */
            $args = $call['args'];
            $row = ['id' => $call['id'], 'name' => $call['name'], 'args' => $args];
            if (self::positiveInt($call['approval'] ?? null)) {
                $row['approval'] = (int) $call['approval'];
            }
            if (\in_array($call['decision'] ?? null, [ChatRunState::DECISION_APPROVED, ChatRunState::DECISION_REJECTED], true)) {
                $row['decision'] = $call['decision'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<array{text: string, state: string}>
     */
    private static function plan(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $item) {
            if (\is_array($item) && \is_string($item['text'] ?? null) && \in_array($item['state'] ?? null, ChatRunState::PLAN_STATES, true)) {
                $out[] = ['text' => $item['text'], 'state' => (string) $item['state']];
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private static function reads(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $hash => $index) {
            if (\is_string($hash) && \is_int($index) && $index >= 0) {
                $out[$hash] = $index;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function opened(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $name) {
            if (\is_string($name) && 1 === preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name) && !\in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return \array_slice($out, 0, ConnectedToolCatalog::MAX_OPEN);
    }

    private static function positiveInt(mixed $value): bool
    {
        return \is_int($value) && $value > 0;
    }

    private static function optionalString(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
