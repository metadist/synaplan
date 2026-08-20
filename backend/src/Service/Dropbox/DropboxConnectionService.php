<?php

declare(strict_types=1);

namespace App\Service\Dropbox;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\OAuth\OAuthConsentService;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthReauthRequiredException;
use App\Service\OAuth\OAuthTokenStore;

/**
 * Dropbox-specific half of the connection lifecycle: name the connection
 * after the signed-in account, verify it against the API, and disconnect it.
 *
 * Deliberately the same shape as
 * {@see \App\Service\Microsoft\MicrosoftConnectionService} — the OAuth
 * framework underneath stays provider-agnostic.
 */
final readonly class DropboxConnectionService
{
    public function __construct(
        private OAuthConsentService $consent,
        private DropboxClient $dropbox,
        private OAuthTokenStore $tokens,
        private ConnectionRepository $connections,
        private DropboxOAuthConfig $config,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->isConfigured();
    }

    public function authorizationUrl(int $ownerId): string
    {
        return $this->consent->authorizationUrl($ownerId, DropboxOAuthConfig::PROVIDER);
    }

    /**
     * Finish consent, then label the connection with the account it actually
     * points at. A failure to read the account is not fatal — the tokens are
     * already valid, and a connection named "Dropbox" beats losing the grant
     * the user just approved.
     */
    public function completeConsent(string $code, string $state): Connection
    {
        $connection = $this->consent->completeConsent(DropboxOAuthConfig::PROVIDER, $code, $state);

        try {
            $this->applyAccountIdentity($connection, $this->dropbox->currentAccount($connection));
        } catch (DropboxException|OAuthException) {
            // Leave the default name; the next successful test will fix it.
        }

        return $connection;
    }

    /**
     * @return array{success: bool, status: string, account?: string, error?: string}
     */
    public function test(Connection $connection): array
    {
        try {
            $account = $this->dropbox->currentAccount($connection);
        } catch (OAuthReauthRequiredException $e) {
            // The provider already marked the connection; report it as-is.
            return ['success' => false, 'status' => Connection::STATUS_REAUTH_REQUIRED, 'error' => $e->getMessage()];
        } catch (DropboxException|OAuthException $e) {
            $connection->markChecked(Connection::STATUS_ERROR);
            $this->connections->save($connection);

            return ['success' => false, 'status' => Connection::STATUS_ERROR, 'error' => $e->getMessage()];
        }

        $this->applyAccountIdentity($connection, $account);
        $connection->markChecked(Connection::STATUS_CONNECTED);
        $this->connections->save($connection);

        return [
            'success' => true,
            'status' => Connection::STATUS_CONNECTED,
            'account' => $this->accountLabel($account),
        ];
    }

    /**
     * Drop the grant locally. Dropbox keeps its own record of the consent —
     * the user revokes that under dropbox.com → Settings → Connected apps.
     */
    public function disconnect(Connection $connection): void
    {
        $this->tokens->forget($connection);
        $connection->markChecked(Connection::STATUS_DISCONNECTED);
        $this->connections->save($connection);
    }

    /**
     * Remove every Dropbox connection on this installation — rows and stored
     * tokens — so all users can redo the OAuth registration from a clean
     * slate. The admin uses this after the Dropbox app or its permission set
     * changed, when every existing grant is stale by definition.
     *
     * Dropbox keeps its own consent record; users revoke that under
     * dropbox.com → Settings → Connected apps.
     *
     * @return int number of connections removed
     */
    public function resetAll(): int
    {
        $removed = 0;
        foreach ($this->connections->findByType(Connection::TYPE_DROPBOX) as $connection) {
            $this->tokens->forget($connection);
            $this->connections->remove($connection);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @param array{accountId: string, name: string, email: string} $account
     */
    private function applyAccountIdentity(Connection $connection, array $account): void
    {
        $label = $this->accountLabel($account);
        if ('' !== $label) {
            $connection->setName($label);
        }

        $config = $connection->getConfig() ?? [];
        $config['provider'] = DropboxOAuthConfig::PROVIDER;
        $config['account_id'] = $account['accountId'];
        $connection->setConfig($config);

        $this->connections->save($connection);
    }

    /**
     * @param array{accountId: string, name: string, email: string} $account
     */
    private function accountLabel(array $account): string
    {
        foreach (['email', 'name'] as $key) {
            if ('' !== $account[$key]) {
                return $account[$key];
            }
        }

        return '';
    }
}
