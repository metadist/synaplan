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
 * The answer is added to the live-voice usage row. A spoken answer is not a
 * MESSAGES row: the assistant speaks it, and the spoken transcript is the one
 * reply the chat keeps, so a delegated answer never shows up twice.
 * A picture, video, audio or document cannot be spoken, so that one becomes
 * the same assistant message the typed chat shows ({@see VoiceLiveArtifact}).
 */
final readonly class VoiceLiveDelegationService
{
    private const SPOKEN_CHARS = 1500;
    private const UNREACHABLE = 'I could not reach the answer. Please try again.';

    public function __construct(
        private MessageProcessor $processor,
        private VoiceLiveUsageService $usage,
        private VoiceTranscriptWriter $transcripts,
        private CostCalculationService $costs,
        private ModelConfigService $modelConfig,
        private UserRepository $users,
        private LoggerInterface $logger,
        private VoiceReplyStamp $replies = new VoiceReplyStamp(),
        private ?VoiceLiveArtifact $artifacts = null,
    ) {
    }

    /**
     * @return array{text: string, facts: list<string>, artifact: array<string, mixed>|null}
     */
    public function answer(VoiceSession $session, string $question): array
    {
        $question = trim($question);
        if ('' === $question || 'ended' === $session->getStatus()) {
            return $this->reply('');
        }

        $user = $this->users->find($session->getUserId());
        if (!$user instanceof User) {
            return $this->reply(self::UNREACHABLE);
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
                // A picture or video is billed the moment the provider bills
                // it, exactly like the typed chat.
                'record_media_usage' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Live voice delegation failed', [
                'session_id' => $session->getId(),
                'error' => $e::class,
            ]);

            return $this->reply(self::UNREACHABLE);
        }

        if (true !== ($result['success'] ?? false)) {
            return $this->reply(self::UNREACHABLE);
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $text = trim((string) ($response['content'] ?? ''));
        $cost = $this->charge($session, $user, $response);
        $pending = $incognito ? [] : $this->replies->fromProcess(
            $result,
            $this->usage->chargedAmount($session, $cost->totalCost),
            false !== ($cost->priceSnapshot['price_known'] ?? true),
        );

        $made = $this->artifacts?->capture($session, $message, $result, $pending, $incognito);
        if (null !== $made) {
            return $this->reply($made['spoken'], $made['artifact']);
        }

        if ('' === $text) {
            return $this->reply(self::UNREACHABLE);
        }
        if (!$incognito) {
            $this->usage->savePendingReply($session, $pending);
        }

        return $this->reply($text);
    }

    /**
     * @param array<string, mixed>|null $artifact
     *
     * @return array{text: string, facts: list<string>, artifact: array<string, mixed>|null}
     */
    private function reply(string $text, ?array $artifact = null): array
    {
        return ['text' => mb_substr($text, 0, self::SPOKEN_CHARS), 'facts' => [], 'artifact' => $artifact];
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
