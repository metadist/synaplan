<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\Effort\AutoResearchGate;
use App\Service\Message\Effort\AutoResearchText;
use App\Service\Message\Effort\EffortVerdict;
use App\Service\Message\Effort\GateDecision;
use App\Service\Message\Effort\GateTurn;
use App\Service\Message\MessageSorter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The chat's judgement, offered to the Anthropic-compatible gateway. With
 * `x-synaplan-effort: auto` the last thing the person typed is rated by the
 * same `tools:sort` call the chat uses, and a `research` rating goes through
 * the same {@see AutoResearchGate}. A started research answers the turn
 * with the notice sentence; every other rating lets the turn run as usual
 * and only reports itself in the response headers.
 *
 * Without the header this class is never asked.
 */
final readonly class GatewayAutoResearch
{
    /** Idempotency prefix: one research per user and question, however often the POST is retried. */
    private const KEY_PREFIX = 'gw:';

    public function __construct(
        private MessageSorter $sorter,
        private AutoResearchGate $gate,
        private AutoResearchText $text,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $prepared the gateway's prepared turn
     */
    public function rate(Request $request, array $prepared, User $user): ?GatewayEffortRating
    {
        if (GatewayEffortHeader::AUTO !== GatewayEffortHeader::fromRequest($request)) {
            return null;
        }
        $body = \is_array($prepared['request_body'] ?? null) ? $prepared['request_body'] : [];
        $messages = \is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $question = AnthropicContentText::lastHumanRequest($messages);
        if ('' === $question) {
            return new GatewayEffortRating(EffortVerdict::none(), GateDecision::none(), null);
        }

        $userId = (int) $user->getId();
        $history = self::history($messages, $question);
        try {
            $result = $this->sorter->classify(self::sorterInput($question), $history, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('Gateway effort rating failed; the turn runs as usual', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return new GatewayEffortRating(EffortVerdict::none(), GateDecision::none(), null);
        }
        $verdict = EffortVerdict::fromRawResponse(\is_string($result['raw_response'] ?? null) ? $result['raw_response'] : null);
        $language = \is_string($result['language'] ?? null) && '' !== $result['language'] ? $result['language'] : 'en';
        $options = DesktopTurnOptions::fromRequest($request);

        $decision = $this->gate->decideFor($user, new GateTurn(
            question: $question,
            verdict: $verdict,
            folder: $options->ragGroupKey,
            language: $language,
            turns: AutoResearchGate::turns($history),
            chatId: null,
            assistantId: $options->agentId,
            idempotencyKey: self::KEY_PREFIX.$userId.':'.substr(sha1($question), 0, 16),
        ));
        $notice = $decision->startedRun() ? $this->text->notice($decision->minutes, (string) $decision->why, $language) : null;

        return new GatewayEffortRating($verdict, $decision, $notice);
    }

    /**
     * @return array<string, mixed> the sorter's input row for a question that has no stored message
     */
    private static function sorterInput(string $question): array
    {
        return [
            'BDATETIME' => date('YmdHis'),
            'BFILEPATH' => '',
            'BTOPIC' => '',
            'BLANG' => 'en',
            'BTEXT' => $question,
            'BFILETEXT' => '',
            'BFILE' => 0,
            'BWEBSEARCH' => 0,
        ];
    }

    /**
     * The turns before the question, as the sorter and the brief expect
     * them: text a person would recognize, tool blocks dropped.
     *
     * @param array<int|string, mixed> $messages
     *
     * @return list<Message>
     */
    private static function history(array $messages, string $question): array
    {
        $history = [];
        foreach ($messages as $message) {
            if (!\is_array($message) || !\in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $text = trim(AnthropicContentText::humanText($message['content'] ?? ''));
            if ('' === $text) {
                continue;
            }
            $turn = new Message();
            $turn->setDirection('assistant' === $message['role'] ? 'OUT' : 'IN');
            $turn->setText($text);
            $history[] = $turn;
        }
        $last = end($history);
        if ($last instanceof Message && 'IN' === $last->getDirection() && $last->getText() === $question) {
            array_pop($history);
        }

        return $history;
    }
}
