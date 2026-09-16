<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Service\DiscordNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Reports chat provider failures to the operator channel.
 *
 * A single broken credential fails every turn of every user, so the raw
 * diagnostics are throttled per provider + reason. Operators need to learn that
 * a provider is down once, not once per message.
 */
final readonly class ChatErrorNotifier
{
    private const THROTTLE_SECONDS = 900;

    public function __construct(
        private DiscordNotificationService $discord,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function notify(ChatErrorView $view, ?string $provider, ?int $userId, array $metadata = []): void
    {
        if (!$this->discord->isEnabled() || !$this->shouldNotify($provider, $view->reason->value)) {
            return;
        }

        try {
            $this->discord->notifyChatError(
                $view->rawMessage,
                $view->reason->value,
                $provider,
                $userId,
                $metadata,
            );
        } catch (\Throwable $e) {
            $this->logger->debug('Discord chat error notification failed (non-critical)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldNotify(?string $provider, string $reason): bool
    {
        $key = 'chat_error_notice_'.md5(($provider ?? 'unknown').'|'.$reason);

        try {
            $isFirstInWindow = false;
            $this->cache->get($key, function ($item) use (&$isFirstInWindow) {
                $item->expiresAfter(self::THROTTLE_SECONDS);
                $isFirstInWindow = true;

                return true;
            });

            return $isFirstInWindow;
        } catch (\Throwable $e) {
            // A broken cache must not silence incident reporting.
            $this->logger->debug('Chat error throttle cache unavailable (non-critical)', [
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
