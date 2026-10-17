<?php

namespace App\Service\WhatsApp\Turn;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\RateLimitService;
use App\Service\WhatsApp\WhatsAppAgentBinding;
use App\Service\WhatsApp\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * First stage of a WhatsApp turn: admits the message or answers it early.
 * In order: a redelivery (lock and cache, so concurrent webhooks see it), an
 * unsupported type (acknowledged silently, #633), a verification code from
 * the website, and the account's message limit (told by message).
 */
final readonly class WhatsAppIntake
{
    /**
     * Cache TTL for duplicate detection (5 minutes).
     * WhatsApp retries typically happen within 1-2 minutes.
     */
    private const DUPLICATE_CACHE_TTL = 300;

    /**
     * Allow-list of WhatsApp webhook message types the AI pipeline knows
     * how to handle. Anything outside this set is silently acknowledged
     * to Meta (HTTP 200) without persisting a Message or invoking the
     * AI — see {@see admit()} and issue #633.
     *
     * Filtered-out types include `reaction`, `poll`, `poll_vote`,
     * `ephemeral`, `request_welcome`, `system`, `unsupported`,
     * `interactive`, `button` and `order`. These either carry no
     * user-actionable text payload (reactions, polls) or require
     * dedicated handlers the platform does not yet implement
     * (interactive buttons, catalog orders). Acknowledging them
     * silently keeps Meta from retrying the webhook and stops the
     * "one poll → seven AI replies" cascade described in #633.
     */
    private const SUPPORTED_MESSAGE_TYPES = [
        'text',
        'image',
        'sticker',
        'audio',
        'video',
        'document',
        'location',
        'contacts',
    ];

    public function __construct(
        private WhatsAppService $whatsApp,
        private CacheInterface $cache,
        private LockFactory $lockFactory,
        private RateLimitService $rateLimitService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private int $whatsappUserId,
        private ?WhatsAppAgentBinding $agentBinding = null,
        private ?AgentConfig $agentConfig = null,
    ) {
    }

    /**
     * @throws WhatsAppMessageAnswered
     */
    public function admit(IncomingMessageDto $dto, User $user, bool $isAnonymous): InboundWhatsApp
    {
        $early = $this->checkAndMarkAsDuplicate($dto) ?? $this->handleUnsupportedTypeIfNeeded($dto);
        if (null !== $early) {
            throw WhatsAppMessageAnswered::with($early);
        }

        $effectiveUserId = $isAnonymous ? $this->whatsappUserId : $user->getId();
        $answersBySpeech = WhatsAppInboundContent::shouldSendAudioResponse($dto);
        $this->logger->info('WhatsApp message received', [
            'original_user_id' => $user->getId(),
            'whatsapp_default_user_id' => $this->whatsappUserId,
            'effective_user_id' => $effectiveUserId,
            'from' => $dto->from,
            'to_phone_number_id' => $dto->phoneNumberId,
            'to_display_phone' => $dto->displayPhoneNumber,
            'type' => $dto->type,
            'message_id' => $dto->messageId,
            'should_send_audio' => $answersBySpeech,
            'is_image' => 'image' === $dto->type,
            'is_video' => 'video' === $dto->type,
        ]);

        if ('text' === $dto->type) {
            $trimmedText = trim(strtoupper($dto->incomingMsg['text']['body']));
            if (preg_match('/^[A-Z0-9]{5}$/', $trimmedText)) {
                $verification = $this->handleVerificationCode($trimmedText, $dto->from, $dto->phoneNumberId, $dto->messageId);
                if (null !== $verification) {
                    throw WhatsAppMessageAnswered::with($verification);
                }
            }
        }

        $limit = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$limit['allowed']) {
            throw WhatsAppMessageAnswered::with($this->handleRateLimitExceeded($user, $dto, $limit));
        }

        return new InboundWhatsApp($dto, $user, $isAnonymous, (int) $effectiveUserId, $answersBySpeech, $this->boundAgentId((int) $user->getId()));
    }

    /**
     * Check if message was already processed (duplicate detection).
     *
     * Uses lock + cache for atomic check-and-set to prevent race conditions
     * when multiple webhook requests arrive simultaneously.
     *
     * @return array|null Returns duplicate result array if duplicate, null if new message
     */
    private function checkAndMarkAsDuplicate(IncomingMessageDto $dto): ?array
    {
        $cacheKey = 'whatsapp_msg_'.hash('sha256', $dto->messageId);
        $lockKey = 'whatsapp_lock_'.hash('sha256', $dto->messageId);

        try {
            // Acquire lock to prevent race condition between concurrent requests
            $lock = $this->lockFactory->createLock($lockKey, ttl: 30.0, autoRelease: true);

            if (!$lock->acquire()) {
                // Another request is processing this message - treat as duplicate
                $this->logger->info('WhatsApp: Message locked by another process, treating as duplicate', [
                    'message_id' => $dto->messageId,
                    'from' => $dto->from,
                ]);

                return [
                    'success' => true,
                    'message_id' => $dto->messageId,
                    'duplicate' => true,
                    'response_sent' => false,
                ];
            }

            // Atomic duplicate detection using unique token pattern.
            // CacheInterface::get() calls the callback only on cache MISS.
            // On cache HIT, it returns the previously stored value.
            // By comparing the returned value to our unique token, we know
            // if WE created the entry (new message) or someone else did (duplicate).
            $myToken = bin2hex(random_bytes(8));
            $storedToken = $this->cache->get($cacheKey, function (ItemInterface $item) use ($myToken): string {
                $item->expiresAfter(self::DUPLICATE_CACHE_TTL);

                return $myToken;
            });

            if ($storedToken !== $myToken) {
                // Cache HIT - another request already stored a different token
                $this->logger->info('WhatsApp: Duplicate message detected (cache hit)', [
                    'message_id' => $dto->messageId,
                    'from' => $dto->from,
                ]);

                $lock->release();

                return [
                    'success' => true,
                    'message_id' => $dto->messageId,
                    'duplicate' => true,
                    'response_sent' => false,
                ];
            }

            // Cache MISS - our callback ran, this is a new message
            $lock->release();

            return null;
        } catch (\Throwable $e) {
            // Graceful degradation: if cache/lock fails, process the message anyway
            // Better to risk a duplicate than to drop messages entirely
            $this->logger->warning('WhatsApp: Duplicate detection failed, processing anyway', [
                'message_id' => $dto->messageId,
                'from' => $dto->from,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Drop non-actionable webhook message types before they enter the
     * AI pipeline. Returns a webhook-success payload when the type is
     * not in {@see SUPPORTED_MESSAGE_TYPES}, or `null` to continue
     * normal processing.
     *
     * We also fire-and-forget a read receipt so the user's WhatsApp
     * thread doesn't show an unread bubble for a poll/reaction the
     * platform legitimately ignored — failures here are logged but
     * never block the webhook acknowledgement (Meta would otherwise
     * retry, compounding the original bug from issue #633).
     */
    private function handleUnsupportedTypeIfNeeded(IncomingMessageDto $dto): ?array
    {
        if (in_array($dto->type, self::SUPPORTED_MESSAGE_TYPES, true)) {
            return null;
        }

        $this->logger->info('WhatsApp: Skipping unsupported message type', [
            'message_id' => $dto->messageId,
            'from' => $dto->from,
            'type' => $dto->type,
        ]);

        if ($this->whatsApp->isAvailable() && '' !== $dto->phoneNumberId) {
            try {
                $this->whatsApp->markAsRead($dto->messageId, $dto->phoneNumberId);
            } catch (\Throwable $e) {
                // Read receipts are best-effort. A failure here must
                // not bubble up: the webhook MUST still return 200
                // so Meta doesn't retry and re-trigger the cascade.
                $this->logger->warning('WhatsApp: Failed to mark unsupported message as read', [
                    'message_id' => $dto->messageId,
                    'type' => $dto->type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'message_id' => $dto->messageId,
            'skipped' => true,
            'reason' => 'unsupported_type',
            'type' => $dto->type,
            'response_sent' => false,
        ];
    }

    /**
     * Handle verification code sent via WhatsApp.
     * Returns array if code is found and processed, null if not a verification code.
     */
    private function handleVerificationCode(string $code, string $fromPhone, string $phoneNumberId, string $messageId): ?array
    {
        // Format phone number consistently
        $fromPhoneFormatted = preg_replace('/[^0-9]/', '', $fromPhone);

        // Find user with pending verification for this code
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(User::class, 'u')
            ->where($qb->expr()->like('u.userDetails', ':codePattern'))
            ->setParameter('codePattern', '%"code":"'.$code.'"%');

        $users = $qb->getQuery()->getResult();

        foreach ($users as $user) {
            $userDetails = $user->getUserDetails();
            $verification = $userDetails['phone_verification'] ?? null;

            if (!$verification || $verification['code'] !== $code) {
                continue;
            }

            $expectedPhone = preg_replace('/[^0-9]/', '', $verification['phone_number']);

            if ($expectedPhone !== $fromPhoneFormatted) {
                $errorMessage = "❌ *Verification Failed*\n\nThis code was requested for a different phone number.\n\nPlease use the phone number you entered on the website.";
                $this->whatsApp->sendMessage($fromPhone, $errorMessage, $phoneNumberId);

                return [
                    'success' => false,
                    'message_id' => $messageId,
                    'error' => 'Phone number mismatch',
                ];
            }

            // Check expiry
            if (time() > ($verification['expires_at'] ?? 0)) {
                $expiredMessage = "❌ *Verification Code Expired*\n\nYour verification code has expired. Please request a new code on the website.\n\nCodes are valid for 5 minutes only.";
                $this->whatsApp->sendMessage($fromPhone, $expiredMessage, $phoneNumberId);

                unset($userDetails['phone_verification']);
                $user->setUserDetails($userDetails);
                $this->em->flush();

                return [
                    'success' => false,
                    'message_id' => $messageId,
                    'error' => 'Code expired',
                ];
            }

            // Success
            $userDetails['phone_number'] = $fromPhoneFormatted;
            $userDetails['phone_verified_at'] = time();
            unset($userDetails['phone_verification']);

            if ('ANONYMOUS' === $user->getUserLevel()) {
                $user->setUserLevel('NEW');
            }

            $user->setUserDetails($userDetails);
            $this->em->flush();

            $this->whatsApp->sendMessage($fromPhone, '✅ Erfolgreich verifiziert!', $phoneNumberId);

            return [
                'success' => true,
                'message_id' => $messageId,
                'verified' => true,
                'user_id' => $user->getId(),
            ];
        }

        return null;
    }

    private function handleRateLimitExceeded(User $user, IncomingMessageDto $dto, array $rateLimitCheck): array
    {
        $this->logger->warning('WhatsApp message rate limit exceeded', [
            'user_id' => $user->getId(),
            'level' => $user->getRateLimitLevel(),
            'limit' => $rateLimitCheck['limit'],
            'used' => $rateLimitCheck['used'],
            'message_id' => $dto->messageId,
        ]);

        $limitType = $rateLimitCheck['limit_type'] ?? 'lifetime';
        $limitMessage = "⚠️ *Nachrichtenlimit erreicht*\n\n";
        $limitMessage .= "Du hast dein Nachrichtenlimit von {$rateLimitCheck['limit']} Nachrichten ";

        if ('lifetime' === $limitType) {
            $limitMessage .= "erreicht.\n\n";
            $limitMessage .= 'Um weiterhin Synaplan zu nutzen, verifiziere deine Nummer oder upgrade zu einem kostenpflichtigen Plan.';
        } else {
            $resetTime = $rateLimitCheck['reset_at'] ?? null;
            if ($resetTime) {
                $resetDate = date('d.m.Y H:i', $resetTime);
                $limitMessage .= "für diesen Zeitraum erreicht.\n\n";
                $limitMessage .= "Dein Limit wird am $resetDate zurückgesetzt.";
            } else {
                $limitMessage .= 'erreicht.';
            }
        }

        $this->whatsApp->sendMessage($dto->from, $limitMessage, $dto->phoneNumberId);
        $this->whatsApp->markAsRead($dto->messageId, $dto->phoneNumberId);

        return [
            'success' => false,
            'message_id' => $dto->messageId,
            'error' => 'Rate limit exceeded',
        ];
    }

    /**
     * The assistant this user's WhatsApp number is pinned to (the `whatsapp`
     * event), or null when Agent Builder is off or nothing is bound.
     */
    private function boundAgentId(int $userId): ?int
    {
        if (null === $this->agentBinding || null === $this->agentConfig || !$this->agentConfig->isEnabled($userId)) {
            return null;
        }

        return $this->agentBinding->get($userId);
    }
}
