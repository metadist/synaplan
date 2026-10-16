<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Credential\UserProviderKeyResolver;
use App\Entity\Connection;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Module\ModuleRegistry;
use App\Repository\ApiKeyRepository;
use App\Repository\ConnectionRepository;
use App\Repository\CustomToolRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\TelegramBotRepository;
use App\Repository\WidgetRepository;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Dropbox\DropboxOAuthConfig;
use App\Service\Email\EmailChatService;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use App\Service\PlatformLink\PlatformLinkExchangeService;
use App\Service\PlatformLink\PlatformLinksConfig;
use App\Service\Tool\ToolsConfig;
use App\Service\WhatsApp\WhatsAppAgentBinding;

/**
 * One pass over the same checks the app directory used to fan out from the
 * browser. A failed check is "not connected", never a thrown error, so the
 * checklist item still renders.
 */
final readonly class ConnectedAppProbe
{
    public function __construct(
        private ModuleRegistry $modules,
        private TelegramBotRepository $telegramBots,
        private WhatsAppAgentBinding $whatsapp,
        private WidgetRepository $widgets,
        private EmailChatService $email,
        private InboundEmailHandlerRepository $mailboxes,
        private ConnectionRepository $connections,
        private MicrosoftOAuthConfig $microsoft,
        private DropboxOAuthConfig $dropbox,
        private PlatformLinksConfig $platformLinks,
        private PlatformLinkExchangeService $platformLinkExchange,
        private MessagesGatewayConfig $gateway,
        private UserProviderKeyResolver $providerKeys,
        private HiggsfieldCredentialResolver $higgsfield,
        private McpServerConfigRepository $mcpServers,
        private ToolsConfig $toolsConfig,
        private CustomToolRepository $customTools,
        private DesktopAgentConfig $desktop,
        private DesktopDeviceRepository $desktopDevices,
        private ApiKeyRepository $apiKeys,
    ) {
    }

    public function isAnyConnected(User $user): bool
    {
        $userId = (int) $user->getId();
        $facts = new GettingStartedFacts();
        $facts->telegramConfigured = $this->moduleConfigured('telegram');
        $facts->telegramConnected = $this->flag(function () use ($userId): bool {
            $bot = $this->telegramBots->findOneByOwner($userId);

            return $bot instanceof TelegramBot && TelegramBot::STATUS_CONNECTED === $bot->getStatus();
        });
        $facts->whatsappConfigured = $this->moduleConfigured('whatsapp');
        $facts->whatsappBound = $this->flag(fn (): bool => null !== $this->whatsapp->get($userId));
        $facts->widgetCount = $this->countOf(fn (): int => $this->widgets->countByOwner($userId));
        $facts->emailKeyword = $this->text(fn (): string => trim((string) $this->email->getUserEmailKeyword($user)));
        $facts->mailboxCount = $this->countOf(fn (): int => count($this->mailboxes->findByUser($userId)));
        $facts->m365Available = $this->flag(fn (): bool => $this->microsoft->isConfigured());
        $facts->dropboxAvailable = $this->flag(fn (): bool => $this->dropbox->isConfigured());
        $live = GettingStartedRules::liveConnectionFlags(
            $this->flagList(fn (): array => $this->connections->findByOwner($userId))
        );
        $facts->m365Connected = $live['m365'];
        $facts->dropboxConnected = $live['dropbox'];
        $facts->webdavConnected = $live['webdav'];
        $facts->platformLinksEnabled = $this->flag(fn (): bool => $this->platformLinks->isEnabled($userId));
        $facts->platformClients = $this->clients($userId);
        $facts->gatewayEnabled = $this->flag(fn (): bool => $this->gateway->isEnabled($userId));
        $facts->anthropicUserKey = $this->flag(fn (): bool => $this->providerKeys->hasUserKey($userId, 'anthropic'));
        $facts->higgsfieldConfigured = $this->moduleConfigured('higgsfield');
        $facts->higgsfieldUser = $this->flag(function () use ($userId): bool {
            $resolved = $this->higgsfield->resolve($userId);

            return null !== $resolved && 'user' === $resolved['source'];
        });
        $facts->mcpCount = $this->countOf(fn (): int => count($this->mcpServers->findByUser($userId)));
        $facts->customToolsEnabled = $this->flag(fn (): bool => $this->toolsConfig->isCustomHttpEnabled($userId));
        $facts->customToolCount = $this->countOf(fn (): int => count($this->customTools->findByOwner($userId)));
        $facts->desktopEnabled = $this->flag(fn (): bool => $this->desktop->isEnabled($userId));
        $facts->desktopDeviceCount = $this->countOf(fn (): int => count($this->desktopDevices->findByOwner($userId)));
        $facts->apiKeyCount = $this->countOf(fn (): int => count($this->apiKeys->findByOwner($userId)));

        return GettingStartedRules::anyAppConnected($facts, $user->isAdmin());
    }

    /**
     * Unknown modules stay visible, matching the runtime-config default.
     * A module that exists and is not configured is absent from the directory.
     */
    private function moduleConfigured(string $id): bool
    {
        try {
            if (!$this->modules->has($id)) {
                return true;
            }

            return $this->modules->get($id)->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function clients(int $userId): array
    {
        try {
            $clients = [];
            foreach ($this->platformLinkExchange->listForUser($userId) as $link) {
                if ('' !== $link['client']) {
                    $clients[] = $link['client'];
                }
            }

            return $clients;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param callable(): bool $read
     */
    private function flag(callable $read): bool
    {
        try {
            return $read();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param callable(): int $read
     */
    private function countOf(callable $read): int
    {
        try {
            return $read();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param callable(): string $read
     */
    private function text(callable $read): string
    {
        try {
            return $read();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param callable(): list<Connection> $read
     *
     * @return list<Connection>
     */
    private function flagList(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }
}
