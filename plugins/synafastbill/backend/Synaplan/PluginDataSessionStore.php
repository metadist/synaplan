<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Synaplan;

use App\Service\PluginDataService;
use Plugin\Synafastbill\Command\Session\SessionStoreInterface;

/**
 * Synaplan-backed session store: persists pending command state in the generic
 * `plugin_data` table (type=session), so a confirmation can arrive in a later
 * chat turn. Synaplan-coupled — excluded from the offline gate.
 */
final class PluginDataSessionStore implements SessionStoreInterface
{
    private const PLUGIN = 'synafastbill';
    private const TYPE = 'session';

    public function __construct(
        private readonly PluginDataService $pluginData,
        private readonly int $userId,
    ) {
    }

    public function load(string $key): ?array
    {
        $state = $this->pluginData->get($this->userId, self::PLUGIN, self::TYPE, $this->sanitize($key));
        if (!is_array($state)) {
            return null;
        }
        $expiresAt = (int) ($state['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            $this->clear($key);

            return null;
        }

        /* @var array<string, mixed> $state */
        return $state;
    }

    public function save(string $key, array $state): void
    {
        $this->pluginData->set($this->userId, self::PLUGIN, self::TYPE, $this->sanitize($key), $state);
    }

    public function clear(string $key): void
    {
        $this->pluginData->delete($this->userId, self::PLUGIN, self::TYPE, $this->sanitize($key));
    }

    private function sanitize(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9_]/i', '_', $key);
    }
}
