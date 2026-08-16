<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

use App\Entity\Connection;
use App\Repository\ConnectionRepository;
use App\Service\OAuth\OAuthConsentService;
use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthReauthRequiredException;
use App\Service\OAuth\OAuthTokenStore;

/**
 * Microsoft-specific half of the connection lifecycle: name the connection
 * after the signed-in account, verify it against Graph, and disconnect it.
 *
 * Kept out of {@see OAuthConsentService} so the OAuth framework stays free of
 * any Microsoft knowledge — the next provider reuses the framework untouched.
 */
final readonly class MicrosoftConnectionService
{
    public function __construct(
        private OAuthConsentService $consent,
        private GraphClient $graph,
        private OAuthTokenStore $tokens,
        private ConnectionRepository $connections,
        private MicrosoftOAuthConfig $config,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->isConfigured();
    }

    public function authorizationUrl(int $ownerId): string
    {
        return $this->consent->authorizationUrl($ownerId, MicrosoftOAuthConfig::PROVIDER);
    }

    /**
     * Finish consent, then label the connection with the mailbox it actually
     * points at. A failure to read the account is not fatal — the tokens are
     * already valid, and a connection named "Microsoft 365" beats losing the
     * grant the user just approved.
     */
    public function completeConsent(string $code, string $state): Connection
    {
        $connection = $this->consent->completeConsent(MicrosoftOAuthConfig::PROVIDER, $code, $state);

        try {
            $this->applyAccountIdentity($connection, $this->graph->me($connection));
        } catch (GraphException|OAuthException) {
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
            $account = $this->graph->me($connection);
        } catch (OAuthReauthRequiredException $e) {
            // The provider already marked the connection; report it as-is.
            return ['success' => false, 'status' => Connection::STATUS_REAUTH_REQUIRED, 'error' => $e->getMessage()];
        } catch (GraphException|OAuthException $e) {
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
     * Drop the grant locally. Microsoft keeps its own record of the consent —
     * the user revokes that in their account portal, which the UI links to.
     */
    public function disconnect(Connection $connection): void
    {
        $this->tokens->forget($connection);
        $connection->markChecked(Connection::STATUS_DISCONNECTED);
        $this->connections->save($connection);
    }

    /**
     * @param array{id: string, displayName: string, userPrincipalName: string, mail: string} $account
     */
    private function applyAccountIdentity(Connection $connection, array $account): void
    {
        $label = $this->accountLabel($account);
        if ('' !== $label) {
            $connection->setName($label);
        }

        $config = $connection->getConfig() ?? [];
        $config['provider'] = MicrosoftOAuthConfig::PROVIDER;
        $config['account_id'] = $account['id'];
        $config['account_upn'] = $account['userPrincipalName'];
        $connection->setConfig($config);

        $this->connections->save($connection);
    }

    /**
     * @param array{id: string, displayName: string, userPrincipalName: string, mail: string} $account
     */
    private function accountLabel(array $account): string
    {
        foreach (['mail', 'userPrincipalName', 'displayName'] as $key) {
            if ('' !== $account[$key]) {
                return $account[$key];
            }
        }

        return '';
    }
}
