<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\McpOAuthState;
use App\Service\Prompt\PromptService;
use App\Service\Security\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Notion app: one hosted MCP connection per user, plus the chat opt-in.
 *
 * Writing is one switch. It sets both the server write opt-in (`allow_write`)
 * and the chat approval skip (`write_auto`). Delete, move and overwrite stay
 * blocked by tool annotations and {@see NotionWriteGuard}, not by this switch.
 */
final readonly class NotionConnectionService
{
    public const MCP_URL = 'https://mcp.notion.com/mcp';

    public const RETURN_TO = '/apps/notion';

    private const TOPIC = 'general';

    public function __construct(
        private McpClientConfig $config,
        private McpServerConfigRepository $servers,
        private McpOAuthConsentService $consent,
        private EncryptionService $encryption,
        private PromptRepository $prompts,
        private PromptService $promptService,
        private EntityManagerInterface $entityManager,
        private NotionAccountReader $account,
        private NotionGateway $gateway,
        private NotionSharing $sharing,
    ) {
    }

    /**
     * @return array{success: bool, workspace_name: string|null, user_name: string|null, user_email: string|null}
     *
     * @throws NotionAppUnavailableException
     */
    public function account(int $userId): array
    {
        $this->assertAvailable($userId);
        $server = $this->notionServer($userId);
        if (null === $server || McpOAuthState::STATUS_CONNECTED !== $this->present($server)['status']) {
            return ['success' => true, 'workspace_name' => null, 'user_name' => null, 'user_email' => null];
        }

        return ['success' => true, ...$this->account->read($server)];
    }

    /**
     * @return array{success: bool, status: string, server_id: int|null, allow_write: bool, write_auto: bool, sharing: array{shared_by: string|null, can_share: bool, shared_on: bool}}
     *
     * @throws NotionAppUnavailableException
     */
    public function status(int $userId, bool $isAdmin = false): array
    {
        $this->assertAvailable($userId);
        $server = $this->notionServer($userId);
        $present = $this->present($server);
        $connected = McpOAuthState::STATUS_CONNECTED === $present['status'];
        $shared = null !== $this->sharing->sharedServer($userId) ? $this->sharing->describe() : null;

        return [...$present, 'sharing' => [
            'shared_by' => $connected || null === $shared ? null : $shared['owner_name'],
            'can_share' => $isAdmin && $connected,
            'shared_on' => null !== $server && $connected && $this->sharing->isShared($server),
        ]];
    }

    /**
     * An admin shares their own connection with everyone, read-only.
     *
     * @return array{success: bool, status: string, server_id: int|null, allow_write: bool, write_auto: bool, sharing: array{shared_by: string|null, can_share: bool, shared_on: bool}}
     *
     * @throws NotionAppUnavailableException
     * @throws NotionConnectionMissingException
     */
    public function setShared(User $admin, bool $on): array
    {
        $userId = (int) $admin->getId();
        $this->assertAvailable($userId);
        $server = $this->gateway->writable($userId);
        if (null === $server) {
            throw new NotionConnectionMissingException('Connect Notion first. Nothing was shared.');
        }
        try {
            $this->sharing->share($admin, $server, $on);
        } catch (\InvalidArgumentException $e) {
            throw new NotionConnectionMissingException($e->getMessage());
        }

        return $this->status($userId, $admin->isAdmin());
    }

    /**
     * A member turns on Notion in their chat with the shared connection.
     *
     * @throws NotionAppUnavailableException
     * @throws NotionConnectionMissingException
     */
    public function useShared(int $userId): void
    {
        $this->assertAvailable($userId);
        if (null === $this->sharing->sharedServer($userId)) {
            throw new NotionConnectionMissingException('Nobody shares a Notion connection here. Connect your own instead.');
        }
        $this->ensureGeneralChatCanUseNotion($userId);
    }

    /**
     * @return array{success: bool, authorize_url: string, server_id: int}
     *
     * @throws NotionAppUnavailableException
     * @throws McpOAuthException
     */
    public function connect(int $userId): array
    {
        $this->assertAvailable($userId);
        $server = $this->notionServer($userId) ?? $this->create($userId);
        $this->ensureGeneralChatCanUseNotion($userId);
        $url = $this->consent->start($server, $userId, self::RETURN_TO);

        return [
            'success' => true,
            'authorize_url' => $url,
            'server_id' => (int) $server->getId(),
        ];
    }

    /**
     * @return array{success: bool, status: string, server_id: int|null, allow_write: bool, write_auto: bool, sharing: array{shared_by: string|null, can_share: bool, shared_on: bool}}
     *
     * @throws NotionAppUnavailableException
     * @throws NotionConnectionMissingException
     */
    public function setWriting(int $userId, bool $allow, bool $isAdmin = false): array
    {
        $this->assertAvailable($userId);
        $server = $this->notionServer($userId);
        if (null === $server) {
            throw new NotionConnectionMissingException('Connect Notion first. Nothing was changed.');
        }

        $server->setAllowWrite($allow);
        $server->setWriteAuto($allow);
        $this->servers->save($server);

        return $this->status($userId, $isAdmin);
    }

    /**
     * @throws NotionAppUnavailableException
     */
    public function assertAvailable(int $userId): void
    {
        if (!$this->config->isClientEnabled($userId) || !$this->config->isOAuthConnectorsEnabled()) {
            throw new NotionAppUnavailableException('Notion is turned off on this installation.');
        }
    }

    private function notionServer(int $userId): ?McpServerConfig
    {
        return $this->gateway->find($userId);
    }

    private function create(int $userId): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId($userId)
            ->setName('Notion')
            ->setUrl(self::MCP_URL)
            ->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH)
            ->setEnabled(true)
            ->setAllowWrite(false)
            ->setWriteAuto(false);
        $this->servers->save($server);

        return $server;
    }

    /**
     * Chat only calls Notion when the general topic has `tool_mcp` on.
     * A shared prompt that already allows it is left alone. A shared prompt
     * that does not is copied for this user so other people stay unchanged.
     */
    private function ensureGeneralChatCanUseNotion(int $userId): void
    {
        $prompt = $this->prompts->findByTopicAndUser(self::TOPIC, $userId);
        if (!$prompt instanceof Prompt || null === $prompt->getId()) {
            return;
        }

        $metadata = $this->promptService->loadMetadataForPrompt($prompt->getId());
        $already = true === ($metadata['tool_mcp'] ?? false);
        if ($already && ($prompt->getOwnerId() === $userId || 0 === $prompt->getOwnerId())) {
            return;
        }

        $metadata['tool_mcp'] = true;
        if ($prompt->getOwnerId() === $userId) {
            $this->promptService->saveMetadataForPrompt($prompt, $metadata);

            return;
        }

        $copy = $this->promptService->createOwned($userId, self::TOPIC, $prompt->getPrompt(), $prompt->getShortDescription());
        $copy->setLanguage($prompt->getLanguage());
        $copy->setSelectionRules($prompt->getSelectionRules());
        $this->entityManager->flush();
        $this->promptService->saveMetadataForPrompt($copy, $metadata);
    }

    /**
     * @return array{success: bool, status: string, server_id: int|null, allow_write: bool, write_auto: bool}
     */
    private function present(?McpServerConfig $server): array
    {
        if (null === $server) {
            return [
                'success' => true,
                'status' => McpOAuthState::STATUS_NOT_CONNECTED,
                'server_id' => null,
                'allow_write' => false,
                'write_auto' => false,
            ];
        }

        $oauth = $server->isOAuth() ? $server->getDecryptedOAuthState($this->encryption)->status : McpOAuthState::STATUS_NOT_CONNECTED;
        $status = in_array($oauth, [McpOAuthState::STATUS_CONNECTED, McpOAuthState::STATUS_REAUTH_REQUIRED], true)
            ? $oauth
            : McpOAuthState::STATUS_NOT_CONNECTED;

        return [
            'success' => true,
            'status' => $status,
            'server_id' => $server->getId(),
            'allow_write' => $server->allowsWrite(),
            'write_auto' => $server->isWriteAuto(),
        ];
    }
}
