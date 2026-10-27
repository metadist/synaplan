<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpOAuthState;
use App\Service\Security\EncryptionService;

/**
 * One admin's Notion connection, shared read-only with everyone on this
 * installation. Members act as the admin's Notion account, so they can read
 * what that account can read and never write.
 */
final readonly class NotionSharing
{
    private const OWNER = 0;
    private const GROUP = 'NOTION';
    private const SETTING = 'SHARED_SERVER';

    public function __construct(
        private ConfigRepository $config,
        private McpServerConfigRepository $servers,
        private UserRepository $users,
        private EncryptionService $encryption,
    ) {
    }

    /**
     * The shared connection another person may read with, or null.
     */
    public function sharedServer(int $forUserId): ?McpServerConfig
    {
        $server = $this->current();

        return null !== $server && $server->getUserId() !== $forUserId ? $server : null;
    }

    public function isShared(McpServerConfig $server): bool
    {
        return null !== $server->getId() && $this->current()?->getId() === $server->getId();
    }

    /**
     * @return array{server_id: int, owner_name: string}|null
     */
    public function describe(): ?array
    {
        $server = $this->current();
        if (null === $server) {
            return null;
        }
        $owner = $this->users->find($server->getUserId());

        return [
            'server_id' => (int) $server->getId(),
            'owner_name' => $owner instanceof User ? $owner->getDisplayName() : '',
        ];
    }

    public function share(User $admin, McpServerConfig $server, bool $on): void
    {
        if (!$on) {
            if ($this->isShared($server)) {
                $this->config->deleteValue(self::OWNER, self::GROUP, self::SETTING);
            }

            return;
        }
        if (!$admin->isAdmin() || $server->getUserId() !== $admin->getId()) {
            throw new \InvalidArgumentException('Only an admin can share their own Notion connection. Nothing was shared.');
        }
        $this->config->setValue(self::OWNER, self::GROUP, self::SETTING, (string) $server->getId());
    }

    private function current(): ?McpServerConfig
    {
        $id = $this->config->getValue(self::OWNER, self::GROUP, self::SETTING);
        if (null === $id || !ctype_digit($id)) {
            return null;
        }
        $server = $this->servers->find((int) $id);
        if (!$server instanceof McpServerConfig || !$server->isEnabled() || !$server->isOAuth()
            || !NotionToolHints::isNotion($server->getUrl())
            || McpOAuthState::STATUS_CONNECTED !== $server->getDecryptedOAuthState($this->encryption)->status) {
            return null;
        }
        $owner = $this->users->find($server->getUserId());

        return $owner instanceof User && $owner->isAdmin() ? $server : null;
    }
}
