<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\UserProviderKeyResolver;
use App\Entity\Credential;
use App\Entity\InboundEmailHandler;
use App\Entity\McpServerConfig;
use App\Model\SecretColumn;
use App\Model\SecretPlace;
use App\Plug\PlugKeyStore;
use App\Repository\StoredSecretRepository;
use App\Service\Dropbox\DropboxOAuthConfig;
use App\Service\Federation\FederationIdentityStore;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiState;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use App\Service\OAuth\Server\AccessTokenKeyStore;
use App\Service\PlatformLink\NotifyKeyStore;

/**
 * Moves every stored secret to the current format, bound to its place.
 *
 * Earlier releases wrote ciphertexts that do not authenticate or name no
 * place; those stay readable only so this job can replace them. A value this
 * installation's APP_SECRET does not open is counted and left untouched.
 */
final readonly class SecretReencryptor
{
    private const EVERY_SETTING = '*';

    /**
     * BCONFIG rows that hold a ciphertext: group => settings. A setting that
     * ends in `*` matches by prefix. A new encrypted setting belongs here.
     */
    private const CONFIG_SECRETS = [
        ProviderKeyStore::CONFIG_GROUP => [self::EVERY_SETTING],
        PlugKeyStore::CONFIG_GROUP => [self::EVERY_SETTING],
        UserProviderKeyResolver::CONFIG_GROUP => [self::EVERY_SETTING],
        OpenAiCompatibleEndpointRegistry::CONFIG_GROUP => [OpenAiCompatibleEndpointRegistry::SETTING_PREFIX.self::EVERY_SETTING],
        HiggsfieldCredentialResolver::CONFIG_GROUP => [
            HiggsfieldCredentialResolver::SETTING_API_KEY,
            HiggsfieldCredentialResolver::SETTING_API_SECRET,
        ],
        FederationIdentityStore::GROUP => [FederationIdentityStore::SETTING],
        AccessTokenKeyStore::GROUP => [AccessTokenKeyStore::SETTING],
        NotifyKeyStore::GROUP => [NotifyKeyStore::SETTING],
        FreeApiConfig::GROUP => [FreeApiState::PENDING_REVOKE],
        MicrosoftOAuthConfig::CONFIG_GROUP => [MicrosoftOAuthConfig::KEY_CLIENT_SECRET],
        DropboxOAuthConfig::CONFIG_GROUP => [DropboxOAuthConfig::KEY_APP_SECRET],
    ];

    public function __construct(
        private StoredSecretRepository $secrets,
        private EncryptionService $encryption,
    ) {
    }

    /**
     * @param bool $write false for a trial run that changes nothing
     *
     * @return list<SecretReencryptTally> one per place that stores secrets
     */
    public function run(bool $write): array
    {
        $tallies = $this->configTallies($write);
        foreach (SecretColumn::cases() as $column) {
            $tallies[] = SecretColumn::MailboxSmtpPassword === $column
                ? $this->smtpTally($write)
                : $this->columnTally($column, $write);
        }

        return $tallies;
    }

    /**
     * @return list<SecretReencryptTally>
     */
    private function configTallies(bool $write): array
    {
        $outcomes = array_fill_keys(array_keys(self::CONFIG_SECRETS), []);
        foreach ($this->secrets->configRows(array_keys(self::CONFIG_SECRETS)) as $row) {
            $group = $this->configGroup($row['group'], $row['setting']);
            if (null === $group) {
                continue;
            }
            $id = $row['id'];
            $outcomes[$group][] = ['id' => $id, 'outcome' => $this->rewrap(
                $row['value'],
                SecretPlace::config($row['ownerId'], $row['group'], $row['setting']),
                fn (string $replacement): bool => !$write || $this->secrets->replaceConfigValue($id, $row['value'], $replacement),
            )];
        }

        $tallies = [];
        foreach ($outcomes as $group => $rows) {
            $tallies[] = SecretReencryptTally::of('BCONFIG '.$group, $rows);
        }

        return $tallies;
    }

    /**
     * The registered group a row belongs to, or null when the row holds no
     * ciphertext. Compared without case, like the database compares them.
     */
    private function configGroup(string $group, string $setting): ?string
    {
        foreach (self::CONFIG_SECRETS as $known => $settings) {
            if (0 !== strcasecmp($known, $group)) {
                continue;
            }
            foreach ($settings as $pattern) {
                $prefix = rtrim($pattern, self::EVERY_SETTING);
                $matches = $prefix === $pattern
                    ? 0 === strcasecmp($pattern, $setting)
                    : 0 === strncasecmp($prefix, $setting, strlen($prefix));
                if ($matches) {
                    return $known;
                }
            }
        }

        return null;
    }

    private function columnTally(SecretColumn $column, bool $write): SecretReencryptTally
    {
        $outcomes = [];
        foreach ($this->secrets->columnRows($column) as $row) {
            $id = $row['id'];
            $outcomes[] = ['id' => $id, 'outcome' => $this->rewrap(
                $row['value'],
                $this->place($column, $row['ownerId']),
                fn (string $replacement): bool => !$write || $this->secrets->replaceColumnValue($column, $id, $row['value'], $replacement),
            )];
        }

        return SecretReencryptTally::of($column->value, $outcomes);
    }

    /**
     * The SMTP password sits inside the handler's JSON config, so the whole
     * document is replaced with only that member changed.
     */
    private function smtpTally(bool $write): SecretReencryptTally
    {
        $column = SecretColumn::MailboxSmtpPassword;
        $outcomes = [];
        foreach ($this->secrets->columnRows($column) as $row) {
            $config = json_decode($row['value'], true);
            $password = is_array($config) && is_array($config['smtp'] ?? null) ? ($config['smtp']['password'] ?? '') : '';
            if (!is_string($password) || '' === $password) {
                continue;
            }
            $id = $row['id'];
            $outcomes[] = ['id' => $id, 'outcome' => $this->rewrap(
                $password,
                $this->place($column, $row['ownerId']),
                function (string $replacement) use ($write, $column, $id, $row, $config): bool {
                    $config['smtp']['password'] = $replacement;

                    return !$write || $this->secrets->replaceColumnValue($column, $id, $row['value'], json_encode($config, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
                },
            )];
        }

        return SecretReencryptTally::of($column->value.' (smtp.password)', $outcomes);
    }

    private function place(SecretColumn $column, int $ownerId): string
    {
        return match ($column) {
            SecretColumn::MailboxPassword => InboundEmailHandler::passwordPlace($ownerId),
            SecretColumn::MailboxSmtpPassword => InboundEmailHandler::smtpPasswordPlace($ownerId),
            SecretColumn::McpAuthToken => McpServerConfig::authTokenPlace($ownerId),
            SecretColumn::McpOAuthState => McpServerConfig::oauthPlace($ownerId),
            SecretColumn::CredentialSecret => Credential::secretPlace($ownerId),
        };
    }

    /**
     * @param \Closure(string): bool $replace stores the new ciphertext; false when the row changed meanwhile
     */
    private function rewrap(string $stored, string $place, \Closure $replace): string
    {
        try {
            $replacement = $this->encryption->rewrap($stored, $place);
        } catch (\RuntimeException) {
            return SecretReencryptTally::UNREADABLE;
        }
        if (null === $replacement) {
            return SecretReencryptTally::CURRENT;
        }

        return $replace($replacement) ? SecretReencryptTally::REWRITTEN : SecretReencryptTally::CHANGED;
    }
}
