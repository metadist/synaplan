<?php

declare(strict_types=1);

namespace App\Service\WhatsApp;

use App\Repository\ConfigRepository;

/**
 * The one place that reads and writes `BCONFIG WHATSAPP/AGENTID` — the
 * per-user assistant a WhatsApp number is pinned to (the `whatsapp` event).
 */
final readonly class WhatsAppAgentBinding
{
    public const CONFIG_GROUP = 'WHATSAPP';
    public const CONFIG_KEY = 'AGENTID';

    public function __construct(
        private ConfigRepository $config,
    ) {
    }

    public function get(int $userId): ?int
    {
        $id = (int) $this->config->getValue($userId, self::CONFIG_GROUP, self::CONFIG_KEY);

        return $id > 0 ? $id : null;
    }

    public function set(int $userId, ?int $agentId): void
    {
        $this->config->setValue(
            $userId,
            self::CONFIG_GROUP,
            self::CONFIG_KEY,
            null !== $agentId && $agentId > 0 ? (string) $agentId : '',
        );
    }

    public function isBoundTo(int $userId, int $agentId): bool
    {
        return $this->get($userId) === $agentId;
    }
}
