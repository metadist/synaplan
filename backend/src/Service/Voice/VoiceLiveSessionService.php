<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\AI\Credential\ProviderKeyStore;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Exception\VoiceLiveBusyException;
use App\Exception\VoiceLiveRejectedException;
use App\Model\ModelCatalog;
use App\Module\Sidecar\LiveVoiceModule;
use App\Repository\ConfigRepository;
use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Repository\VoiceSessionRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\Message\MessageApiFormatter;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Starts, redeems and closes one live conversation.
 * The provider socket is the sidecar's. This service never sees audio.
 */
final readonly class VoiceLiveSessionService
{
    public function __construct(
        private LiveVoiceModule $module,
        private VoiceLiveUsageService $usage,
        private VoiceLiveTicketService $tickets,
        private VoiceLiveConcurrency $concurrency,
        private VoiceLiveDelegationService $delegation,
        private VoiceTranscriptWriter $transcripts,
        private VoiceLiveInstructions $instructions,
        private ModelRepository $models,
        private ProviderKeyStore $keys,
        private ConfigRepository $config,
        private MessageRepository $messages,
        private VoiceSessionRepository $voiceSessions,
        private UserRepository $users,
        private RateLimitService $limits,
        private PremiumFeatureGate $gate,
        private EntityManagerInterface $em,
        private RedisService $redis,
        private LoggerInterface $logger,
        private string $publicWsPath,
        private VoiceLiveCompatibleEndpoint $compatible,
        private MessageApiFormatter $formatter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function options(User $user): array
    {
        $models = $this->usableModels();
        $default = $this->defaultModel($user, $models);
        $budget = $this->limits->checkCostBudget($user);
        $enforced = $this->gate->isCostBudgetEnforced();
        $unlimited = !$enforced || (float) $budget['budget'] <= 0.0;

        return [
            'available' => $this->module->isConfigured() && [] !== $models,
            'reason' => $this->unavailableReason($models),
            'models' => array_map(fn (Model $model): array => $this->modelCard($model), $models),
            'defaultModelId' => $default?->getId(),
            'estimatedSecondsLeft' => (!$unlimited && $default instanceof Model) ? $this->usage->allowedSeconds($user, $default) : null,
            'budgetExhausted' => $enforced && !$budget['allowed'],
            'costBudget' => $enforced ? [
                'limit' => $budget['budget'],
                'used' => $budget['used_cost'],
                'remaining' => $budget['remaining'],
                'userLevel' => $user->getUserLevel(),
                'phoneVerified' => $user->hasVerifiedPhone(),
                'topupAvailable' => true,
            ] : null,
            'consentGiven' => $this->consentGiven($user),
            'maxSessionSeconds' => $this->usage->maxSessionSeconds(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function start(User $user, array $body, Request $request): array
    {
        if (!$this->module->isConfigured()) {
            throw new VoiceLiveRejectedException(404, ['error' => 'feature_not_configured', 'module' => LiveVoiceModule::ID, 'docs' => $this->module->docsAnchor()]);
        }
        if (!$this->consentGiven($user)) {
            throw new VoiceLiveRejectedException(403, ['error' => 'Allow live voice before starting a conversation.', 'code' => 'VOICE_CONSENT_REQUIRED']);
        }

        $this->limitStarts($user);
        $model = $this->resolveModel($user, $this->intOrNull($body['modelId'] ?? null));
        $provider = strtolower($model->getService());
        $this->assertProviderKey($provider);
        $this->assertBudget($user);
        $this->concurrency->acquire($provider);

        $incognito = true === ($body['incognito'] ?? false);
        $chat = $incognito ? null : $this->chatFor($user, $this->intOrNull($body['chatId'] ?? null));
        $client = $this->string($body['client'] ?? 'web');
        $transport = VoiceLiveTransport::choose($provider, $model->getProviderId(), $this->webrtcOpenAi(), $client);
        try {
            $session = $this->usage->open($user, $model, $chat?->getId(), '' !== $client ? $client : 'web', false, $transport);
        } catch (VoiceLiveBusyException) {
            $this->concurrency->release($provider);
            throw new VoiceLiveRejectedException(409, ['error' => 'A live conversation is already open.', 'code' => 'VOICE_BUSY']);
        }

        $extra = [];
        if ($incognito) {
            $extra['incognito'] = true;
        }
        $voice = $this->chosenVoice($model, $body['voice'] ?? null);
        if ('' !== $voice) {
            $extra['voice'] = $voice;
        }
        $language = $this->string($body['language'] ?? '');
        if (strlen($language) >= 2 && strlen($language) <= 12) {
            $extra['language'] = $language;
        }
        if ([] !== $extra) {
            $this->usage->mergeMetadata($session, $extra);
        }

        try {
            $ticket = $this->tickets->mint((int) $user->getId(), (int) $session->getId());
        } catch (VoiceLiveRejectedException $e) {
            $this->usage->finalizeStored($session, 'provider_error');
            $this->concurrency->release($provider);
            throw $e;
        }

        $this->logger->info('Live voice session started', [
            'session_id' => $session->getId(),
            'provider' => $provider,
            'client' => $session->getClient(),
        ]);

        return [
            'sessionId' => $session->getId(),
            'chatId' => $session->getChatId(),
            'ticket' => $ticket,
            'wsUrl' => $this->wsUrl($request, $ticket),
            'audio' => $this->audio($provider),
            'allowedSeconds' => $this->usage->allowedSeconds($user, $model),
            'warnAtSeconds' => 60,
            'provider' => $provider,
            'model' => $model->getProviderId(),
            'voice' => '' !== $voice ? $voice : $this->voiceName($model),
            'transport' => $session->getTransport(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function end(User $user, int $sessionId, string $reason = 'user_end'): array
    {
        $session = $this->owned($user, $sessionId);
        if ('ended' === $session->getStatus()) {
            return $this->statusPayload($session);
        }
        if (!in_array($reason, ['user_end', 'provider_error'], true)) {
            $reason = 'user_end';
        }

        $this->usage->finalizeStored($session, $reason);
        $this->concurrency->release($session->getProvider());
        $this->logger->info('Live voice session ended', [
            'session_id' => $session->getId(),
            'provider' => $session->getProvider(),
            'reason' => $reason,
        ]);

        return $this->statusPayload($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user, int $sessionId): array
    {
        return $this->statusPayload($this->owned($user, $sessionId));
    }

    /**
     * @return list<array{sessionId: int, userEmail: string, provider: string, seconds: int, started: int, chatId: int|null}>
     */
    public function adminList(): array
    {
        $sessions = $this->voiceSessions->findOpen();
        $emails = $this->emailsFor(array_map(static fn (VoiceSession $session): int => $session->getUserId(), $sessions));
        $rows = [];
        foreach ($sessions as $session) {
            $id = $session->getId();
            if (null === $id) {
                continue;
            }
            $rows[] = [
                'sessionId' => $id,
                'userEmail' => $emails[$session->getUserId()] ?? '',
                'provider' => strtolower($session->getProvider()),
                'seconds' => $session->getSeconds(),
                'started' => $session->getStarted(),
                'chatId' => $session->getChatId(),
            ];
        }
        usort($rows, static fn (array $left, array $right): int => $right['started'] <=> $left['started']);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function adminKill(int $sessionId): array
    {
        $session = $this->em->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession) {
            throw new VoiceLiveRejectedException(404, ['error' => 'Session not found']);
        }
        if ('ended' !== $session->getStatus()) {
            $this->usage->finalizeStored($session, 'admin_disabled');
            $this->concurrency->release($session->getProvider());
            $this->logger->info('Live voice session ended by an admin', [
                'session_id' => $session->getId(),
                'provider' => $session->getProvider(),
            ]);
        }

        return $this->statusPayload($session);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    private function emailsFor(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        $emails = [];
        foreach ($this->users->findBy(['id' => $userIds]) as $user) {
            if ($user instanceof User && null !== $user->getId()) {
                $emails[$user->getId()] = $user->getUserIdentifier();
            }
        }

        return $emails;
    }

    public function setConsent(User $user, bool $given): void
    {
        $this->config->setValue((int) $user->getId(), 'LIVE_VOICE', 'CONSENT', $given ? '1' : '0');
    }

    public function webrtcOpenAi(): bool
    {
        return '1' === $this->config->getValue(0, 'LIVE_VOICE', 'WEBRTC_OPENAI');
    }

    public function setWebrtcOpenAi(bool $enabled): void
    {
        $this->config->setValue(0, 'LIVE_VOICE', 'WEBRTC_OPENAI', $enabled ? '1' : '0');
    }

    /**
     * @return array<string, mixed>
     */
    public function redeem(string $ticket): array
    {
        $claimed = $this->tickets->redeem($ticket);
        $session = $this->em->find(VoiceSession::class, $claimed['sessionId']);
        if (!$session instanceof VoiceSession || $session->getUserId() !== $claimed['userId'] || 'ended' === $session->getStatus()) {
            throw new VoiceLiveRejectedException(401, ['error' => 'Unauthorized']);
        }

        $user = $this->em->find(User::class, $session->getUserId());
        $model = $this->em->find(Model::class, $session->getModelId());
        if (!$user instanceof User || !$model instanceof Model) {
            throw new VoiceLiveRejectedException(401, ['error' => 'Unauthorized']);
        }

        $provider = strtolower($model->getService());

        $storedVoice = $this->usage->stringMeta($session, 'voice');
        $storedLanguage = $this->usage->stringMeta($session, 'language');

        return [
            'sessionId' => $session->getId(),
            'provider' => $provider,
            'model' => $model->getProviderId(),
            'voice' => '' !== $storedVoice ? $storedVoice : $this->voiceName($model),
            'instructions' => $this->instructions->build($user, $this->history($user, $session->getChatId()), '' !== $storedLanguage ? $storedLanguage : null),
            'allowedSeconds' => $this->usage->allowedSeconds($user, $model),
            'audio' => $this->audio($provider),
            'transport' => $session->getTransport(),
            'client' => $session->getClient(),
        ];
    }

    /**
     * A fresh single-use ticket for a session that is still open. CarPlay uses
     * this after a tunnel drop. It does not open a second conversation.
     *
     * @return array<string, mixed>
     */
    public function resumeTicket(User $user, int $sessionId, Request $request): array
    {
        $session = $this->owned($user, $sessionId);
        if ('ended' === $session->getStatus()) {
            throw new VoiceLiveRejectedException(404, ['error' => 'Session not found']);
        }

        $ticket = $this->tickets->mint((int) $user->getId(), $sessionId);

        return [
            'sessionId' => $sessionId,
            'chatId' => $session->getChatId(),
            'ticket' => $ticket,
            'wsUrl' => $this->wsUrl($request, $ticket),
            'audio' => $this->audio(strtolower($session->getProvider())),
            'transport' => $session->getTransport(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function applyUsage(int $sessionId, array $body): array
    {
        $session = $this->openSession($sessionId);
        $decision = $this->usage->tick($session, $this->meter($body), $this->intOrNull($body['latencyMs'] ?? null));
        $this->rememberProviderSession($session, $body);
        $updated = $this->transcripts->attributeVoice($session);

        return [
            'action' => $decision->action,
            'allowedSeconds' => $decision->allowedSeconds,
            'reason' => $decision->reason,
            'details' => $updated instanceof Message ? $this->replyDetails($updated) : null,
        ];
    }

    /**
     * @return array{text: string, facts: list<string>}
     */
    public function delegate(int $sessionId, string $question): array
    {
        return $this->delegation->answer($this->openSession($sessionId), $question);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function recordTranscript(int $sessionId, array $body): array
    {
        $turns = $body['turns'] ?? null;
        if (!is_array($turns)) {
            return ['ok' => true];
        }
        /** @var list<array{role?: mixed, text?: mixed, startMs?: mixed, endMs?: mixed}> $chunks */
        $chunks = [];
        foreach ($turns as $turn) {
            if (is_array($turn)) {
                $chunks[] = $turn;
            }
        }
        // The browser marks the session ended as soon as the person hangs up,
        // while the last sentence may still be on its way. Keep that sentence.
        $session = $this->em->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession) {
            throw new VoiceLiveRejectedException(404, ['error' => 'Session not found']);
        }
        $message = $this->transcripts->record($session, $chunks, $this->meter($body));
        $messageId = $message instanceof Message ? $message->getId() : null;

        return [
            'ok' => true,
            'messageId' => is_int($messageId) ? $messageId : null,
            'details' => $message instanceof Message && 'OUT' === $message->getDirection()
                ? $this->replyDetails($message)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function markClosed(int $sessionId, array $body): void
    {
        $session = $this->em->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession || 'ended' === $session->getStatus()) {
            return;
        }

        $reason = $this->string($body['reason'] ?? 'network_lost');
        $allowed = ['user_end', 'idle', 'max_duration', 'limit_budget', 'provider_error', 'network_lost', 'capacity', 'admin_disabled'];
        if (!in_array($reason, $allowed, true)) {
            $reason = 'network_lost';
        }
        $this->usage->close($session, $reason, $this->meter($body), $this->intOrNull($body['latencyMs'] ?? null));
        $this->transcripts->attributeVoice($session);
        $this->concurrency->release($session->getProvider());
        $this->logger->info('Live voice session closed', [
            'session_id' => $session->getId(),
            'provider' => $session->getProvider(),
            'reason' => $reason,
        ]);
    }

    private function openSession(int $sessionId): VoiceSession
    {
        $session = $this->em->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession || 'ended' === $session->getStatus()) {
            throw new VoiceLiveRejectedException(404, ['error' => 'Session not found']);
        }

        return $session;
    }

    private function owned(User $user, int $sessionId): VoiceSession
    {
        $session = $this->em->find(VoiceSession::class, $sessionId);
        if (!$session instanceof VoiceSession || $session->getUserId() !== (int) $user->getId()) {
            throw new VoiceLiveRejectedException(404, ['error' => 'Session not found']);
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(VoiceSession $session): array
    {
        return [
            'sessionId' => $session->getId(),
            'chatId' => $session->getChatId(),
            'status' => $session->getStatus(),
            'seconds' => $session->getSeconds(),
            'endReason' => $session->getEndReason(),
            'provider' => strtolower($session->getProvider()),
        ];
    }

    /**
     * The same model, topic and usage fields the history API returns, so the
     * open conversation can show them before the next reload.
     *
     * @return array<string, mixed>
     */
    private function replyDetails(Message $message): array
    {
        $row = $this->formatter->format($message);

        return [
            'role' => 'assistant',
            'topic' => $row['topic'] ?? null,
            'aiModels' => $row['aiModels'] ?? null,
            'usage' => $row['usage'] ?? null,
            'usageExtra' => $row['usageExtra'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function meter(array $body): VoiceLiveMeter
    {
        $meter = is_array($body['meter'] ?? null) ? $body['meter'] : $body;

        return new VoiceLiveMeter(
            seconds: $this->floatOf($meter['seconds'] ?? 0),
            audioInTokens: $this->intOf($meter['audioInTokens'] ?? 0),
            audioOutTokens: $this->intOf($meter['audioOutTokens'] ?? 0),
            audioCacheTokens: $this->intOf($meter['audioCacheTokens'] ?? 0),
            textInTokens: $this->intOf($meter['textInTokens'] ?? 0),
            textOutTokens: $this->intOf($meter['textOutTokens'] ?? 0),
            textItems: $this->intOf($meter['textItems'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rememberProviderSession(VoiceSession $session, array $body): void
    {
        $id = $body['providerSessionId'] ?? null;
        if (!is_string($id) || '' === $id || null !== $session->getProviderSessionId()) {
            return;
        }
        $session->setProviderSessionId(substr($id, 0, 128));
        $this->em->flush();
    }

    private function assertBudget(User $user): void
    {
        if (!$this->gate->isCostBudgetEnforced()) {
            return;
        }
        $budget = $this->limits->checkCostBudget($user);
        if ($budget['allowed']) {
            return;
        }

        throw new VoiceLiveRejectedException(429, ['error' => 'Cost budget exceeded', 'code' => 'COST_BUDGET_EXCEEDED', 'limit_type' => 'monthly', 'action_type' => 'MESSAGES', 'limit' => $budget['budget'], 'used' => $budget['used_cost'], 'remaining' => $budget['remaining'], 'topup_available' => true, 'user_level' => $user->getUserLevel(), 'phone_verified' => $user->hasVerifiedPhone()]);
    }

    private function limitStarts(User $user): void
    {
        $key = 'voice:starts:'.(int) $user->getId();
        $count = $this->redis->increment($key);
        if (null === $count) {
            throw new VoiceLiveRejectedException(503, ['error' => 'Live voice is temporarily unavailable.', 'code' => 'VOICE_UNAVAILABLE']);
        }
        if (1 === $count) {
            $this->redis->expire($key, 600);
        }
        if ($count > 10) {
            throw new VoiceLiveRejectedException(429, ['error' => 'Too many live conversations were started. Wait a moment.', 'code' => 'VOICE_RATE']);
        }
    }

    private function assertProviderKey(string $provider): void
    {
        if (VoiceLiveCompatibleEndpoint::PROVIDER === $provider) {
            if ($this->compatible->configured()) {
                return;
            }
            throw new VoiceLiveRejectedException(409, ['error' => 'Live voice has no provider key yet.', 'code' => 'VOICE_UNAVAILABLE']);
        }

        $status = $this->keys->getStatus($provider);
        if ($status['configured']) {
            return;
        }

        throw new VoiceLiveRejectedException(409, ['error' => 'Live voice has no provider key yet.', 'code' => 'VOICE_UNAVAILABLE']);
    }

    private function resolveModel(User $user, ?int $modelId): Model
    {
        if (null !== $modelId) {
            $model = $this->models->find($modelId);
        } else {
            $model = $this->defaultModel($user, $this->usableModels());
        }
        if (!$model instanceof Model || 'voice2voice' !== $model->getTag() || 1 !== $model->getActive()) {
            throw new VoiceLiveRejectedException(409, ['error' => 'Live voice has no model yet.', 'code' => 'VOICE_UNAVAILABLE']);
        }

        return $model;
    }

    /**
     * @return list<Model>
     */
    private function usableModels(): array
    {
        $usable = [];
        foreach ($this->models->findActiveByTag('voice2voice') as $model) {
            $provider = strtolower($model->getService());
            if (VoiceLiveCompatibleEndpoint::PROVIDER === $provider) {
                if ($this->compatible->configured()) {
                    $usable[] = $model;
                }
                continue;
            }
            $status = $this->keys->getStatus($provider);
            if ($status['configured']) {
                $usable[] = $model;
            }
        }

        return $usable;
    }

    /**
     * @param list<Model> $models
     */
    private function defaultModel(User $user, array $models): ?Model
    {
        $key = $this->config->getValue((int) $user->getId(), 'DEFAULTMODEL', 'VOICE_LIVE')
            ?? $this->config->getValue(0, 'DEFAULTMODEL', 'VOICE_LIVE');
        $bid = is_string($key) ? ModelCatalog::findBidByKey($key) : null;
        if (null !== $bid) {
            foreach ($models as $model) {
                if ($model->getId() === $bid) {
                    return $model;
                }
            }
        }

        return $models[0] ?? null;
    }

    /**
     * @param list<Model> $models
     */
    private function unavailableReason(array $models): string
    {
        if (!$this->module->isConfigured()) {
            return 'not_configured';
        }
        if ([] === $models) {
            return 'no_model';
        }

        return '';
    }

    /**
     * @return array{id: int|null, name: string, provider: string, voice: string, voices: list<string>}
     */
    private function modelCard(Model $model): array
    {
        $voices = $this->voices($model);

        return [
            'id' => $model->getId(),
            'name' => $model->getName(),
            'provider' => strtolower($model->getService()),
            'voice' => $voices[0] ?? '',
            'voices' => $voices,
        ];
    }

    /**
     * @return list<string>
     */
    private function voices(Model $model): array
    {
        $voices = $model->getJson()['voices'] ?? null;
        if (!is_array($voices)) {
            return [];
        }
        $names = [];
        foreach ($voices as $voice) {
            if (is_string($voice) && '' !== $voice) {
                $names[] = $voice;
            }
        }

        return $names;
    }

    private function chosenVoice(Model $model, mixed $requested): string
    {
        $voices = $this->voices($model);
        if (is_string($requested) && in_array($requested, $voices, true)) {
            return $requested;
        }

        return $voices[0] ?? '';
    }

    private function voiceName(Model $model): string
    {
        $voices = $model->getJson()['voices'] ?? null;
        if (is_array($voices) && isset($voices[0]) && is_string($voices[0]) && '' !== $voices[0]) {
            return $voices[0];
        }

        return '';
    }

    /**
     * @return array{format: string, inRate: int, outRate: int}
     */
    private function audio(string $provider): array
    {
        if ('google' === $provider) {
            return ['format' => 'pcm16', 'inRate' => 16000, 'outRate' => 24000];
        }

        return ['format' => 'pcm16', 'inRate' => 24000, 'outRate' => 24000];
    }

    private function chatFor(User $user, ?int $chatId): Chat
    {
        if (null !== $chatId) {
            $chat = $this->em->find(Chat::class, $chatId);
            if ($chat instanceof Chat && $chat->getUserId() === (int) $user->getId()) {
                return $chat;
            }
        }

        $chat = (new Chat())
            ->setUserId((int) $user->getId())
            ->setTitle('New Chat')
            ->setSource('web');
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function history(User $user, ?int $chatId): string
    {
        if (null === $chatId) {
            return '';
        }

        $lines = [];
        $found = $this->messages->findChatHistory((int) $user->getId(), $chatId, 20, 24000);
        foreach (array_reverse($found) as $message) {
            $who = 'IN' === $message->getDirection() ? 'User' : 'Assistant';
            $lines[] = $who.': '.trim($message->getText());
        }

        return implode("\n", $lines);
    }

    private function consentGiven(User $user): bool
    {
        return '1' === $this->config->getValue((int) $user->getId(), 'LIVE_VOICE', 'CONSENT');
    }

    private function wsUrl(Request $request, string $ticket): string
    {
        $scheme = $request->isSecure() ? 'wss' : 'ws';
        $path = '/'.ltrim($this->publicWsPath, '/');

        return $scheme.'://'.$request->getHttpHost().$path.'?ticket='.rawurlencode($ticket);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
