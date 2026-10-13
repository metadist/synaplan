<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\DTO\CostResult;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\UserRepository;
use App\Service\CostCalculationService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

/**
 * One spoken question goes through the user's normal chat pipeline.
 * The answer is added to the live-voice usage row. It is not a MESSAGES row:
 * the assistant speaks it, and the spoken transcript is the one reply the
 * chat keeps, so a delegated answer never shows up twice.
 */
final readonly class VoiceLiveDelegationService
{
    private const SPOKEN_CHARS = 1500;

    public function __construct(
        private MessageProcessor $processor,
        private VoiceLiveUsageService $usage,
        private VoiceTranscriptWriter $transcripts,
        private CostCalculationService $costs,
        private ModelConfigService $modelConfig,
        private UserRepository $users,
        private LoggerInterface $logger,
        private VoiceReplyStamp $replies = new VoiceReplyStamp(),
    ) {
    }

    /**
     * @return array{text: string, facts: list<string>}
     */
    public function answer(VoiceSession $session, string $question): array
    {
        $question = trim($question);
        if ('' === $question || 'ended' === $session->getStatus()) {
            return ['text' => '', 'facts' => []];
        }

        $user = $this->users->find($session->getUserId());
        if (!$user instanceof User) {
            return ['text' => 'I could not reach the answer. Please try again.', 'facts' => []];
        }

        $incognito = $this->usage->isIncognito($session);
        $message = $incognito ? $this->transient($session, $user, $question) : $this->transcripts->record($session, [[
            'role' => 'user',
            'text' => $question,
            'startMs' => 0,
            'endMs' => 0,
        ]]);
        if (!$message instanceof Message) {
            $message = $this->transient($session, $user, $question);
        }

        try {
            $result = $this->processor->process($message, [
                'incognito' => $incognito,
                'source' => 'live_voice',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Live voice delegation failed', [
                'session_id' => $session->getId(),
                'error' => $e::class,
            ]);

            return ['text' => 'I could not reach the answer. Please try again.', 'facts' => []];
        }

        if (true !== ($result['success'] ?? false)) {
            return ['text' => 'I could not reach the answer. Please try again.', 'facts' => []];
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $text = trim((string) ($response['content'] ?? ''));
        if ('' === $text) {
            return ['text' => 'I could not reach the answer. Please try again.', 'facts' => []];
        }

        $cost = $this->charge($session, $user, $response);
        if (!$incognito) {
            $this->usage->savePendingReply($session, $this->replies->fromProcess(
                $result,
                $this->usage->chargedAmount($session, $cost->totalCost),
                false !== ($cost->priceSnapshot['price_known'] ?? true),
            ));
        }

        return ['text' => mb_substr($text, 0, self::SPOKEN_CHARS), 'facts' => []];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function charge(VoiceSession $session, User $user, array $response): CostResult
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $prompt = $this->tokens($usage, 'prompt_tokens', 'input_tokens');
        $completion = $this->tokens($usage, 'completion_tokens', 'output_tokens');
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $modelId = is_numeric($metadata['model_id'] ?? null)
            ? (int) $metadata['model_id']
            : $this->modelConfig->getDefaultModel('CHAT', (int) $user->getId());
        $cost = $this->costs->calculateCost($prompt, $completion, 0, 0, $modelId);
        $this->usage->addAnswer($session, $cost->totalCost, $prompt, $completion);

        return $cost;
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function tokens(array $usage, string $primary, string $fallback): int
    {
        $value = $usage[$primary] ?? $usage[$fallback] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function transient(VoiceSession $session, User $user, string $question): Message
    {
        $locale = substr($user->getLocale(), 0, 2);

        return (new Message())
            ->setUserId((int) $user->getId())
            ->setChatId($session->getChatId())
            ->setTrackingId((int) (microtime(true) * 1000))
            ->setProviderIndex('WEB')
            ->setUnixTimestamp(time())
            ->setDateTime(date('YmdHis'))
            ->setMessageType('WEB')
            ->setTopic('CHAT')
            ->setLanguage(2 === strlen($locale) ? $locale : 'en')
            ->setText($question)
            ->setDirection('IN')
            ->setStatus('processing');
    }
}
