<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\PlatformInstanceRepository;
use App\Security\ApiKeyScope;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;

/**
 * Outlook add-in connect (Synamail). Mints the add-in key for the signed-in
 * user and builds the relay redirect on the server, so the browser never
 * decides where the key travels: the relay host must prefix-match the
 * `outlook-builtin` row in BPLATFORMINSTANCES (today's `isSafeRedirect()`
 * allow-list, moved server-side by More Nextcloud S1 / NC1).
 *
 * Not gated by PLATFORM_LINKS.ENABLED — Outlook connect is shipped behaviour.
 */
final readonly class OutlookConnectService
{
    public const STATE_MAX_LENGTH = PlatformInstanceService::STATE_MAX_LENGTH;
    private const BASE_URL_MAX_LENGTH = 2048;

    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private PlatformInstanceRepository $instanceRepository,
        private RedirectUriPolicy $redirectUriPolicy,
        private AuditLogWriter $auditLogWriter,
    ) {
    }

    /**
     * @return array{
     *   redirect: string|null,
     *   payload: array{state: string, apiKey: string, keyId: int, email: string, baseUrl: string}
     * }
     */
    public function connect(
        User $user,
        string $state,
        string $redirectUri,
        string $baseUrl,
        string $requestOrigin,
        string $userAgent,
        string $ip,
    ): array {
        $state = trim($state);
        if ('' === $state) {
            throw new PlatformLinkValidationException('state is required.');
        }
        if (mb_strlen($state) > self::STATE_MAX_LENGTH) {
            throw new PlatformLinkValidationException(sprintf('state must be at most %d characters.', self::STATE_MAX_LENGTH));
        }

        $redirectUri = trim($redirectUri);
        $relay = '' === $redirectUri ? null : $this->acceptedRelay($user, $redirectUri, $ip);

        $plainKey = 'sk_'.bin2hex(random_bytes(29));
        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setKey($plainKey);
        $apiKey->setName($this->keyName($userAgent));
        $apiKey->setStatus('active');
        $apiKey->setScopes(ApiKeyScope::addinScopes());
        $this->apiKeyRepository->save($apiKey);

        $payload = [
            'state' => $state,
            'apiKey' => $plainKey,
            'keyId' => (int) $apiKey->getId(),
            'email' => (string) $user->getMail(),
            'baseUrl' => $this->resolveBaseUrl($baseUrl, $requestOrigin),
        ];

        return [
            'redirect' => null === $relay ? null : $this->buildRelayRedirect($relay, $payload),
            'payload' => $payload,
        ];
    }

    /**
     * Returns the relay URI when it prefix-matches the Outlook built-in
     * allow-list, null otherwise (the caller falls back to Office.js
     * messageParent). Rejections are audited like partner redirects.
     */
    private function acceptedRelay(User $user, string $redirectUri, string $ip): ?string
    {
        $builtin = $this->instanceRepository->findOutlookBuiltin();
        $accepted = $builtin instanceof PlatformInstance
            && $builtin->isActive()
            && $this->redirectUriPolicy->matchesRegisteredPrefix($redirectUri, $builtin->getHost(), $builtin->getRedirectUris());

        if ($accepted) {
            return $redirectUri;
        }

        $this->auditLogWriter->record(
            (int) $user->getId(),
            'platform_link.redirect_rejected',
            'platform_instance',
            PlatformInstance::OUTLOOK_BUILTIN_ID,
            ['redirect_uri' => $redirectUri],
            $ip,
        );

        return null;
    }

    /**
     * Same wire format the bridge used before: `<relay>#payload=<base64 JSON>`
     * (`&payload=` when the relay already carries a fragment). Synamail's
     * auth-relay.ts reads exactly this.
     *
     * @param array{state: string, apiKey: string, keyId: int, email: string, baseUrl: string} $payload
     */
    private function buildRelayRedirect(string $relay, array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $separator = str_contains($relay, '#') ? '&' : '#';

        return $relay.$separator.'payload='.rawurlencode(base64_encode($json));
    }

    /**
     * The base URL the add-in will call. The bridge sends the SPA origin (or
     * the explicit `baseUrl` query param the dialog was opened with); anything
     * that is not a plain http(s) origin falls back to the request origin.
     */
    private function resolveBaseUrl(string $baseUrl, string $requestOrigin): string
    {
        $baseUrl = trim($baseUrl);
        if ('' === $baseUrl || mb_strlen($baseUrl) > self::BASE_URL_MAX_LENGTH) {
            return $requestOrigin;
        }
        $parsed = parse_url($baseUrl);
        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return $requestOrigin;
        }
        $scheme = strtolower((string) $parsed['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true) || isset($parsed['user'], $parsed['pass'])) {
            return $requestOrigin;
        }

        return rtrim($baseUrl, '/');
    }

    /** Mirrors the key label the bridge used to build in the browser. */
    private function keyName(string $userAgent): string
    {
        $host = 'browser';
        if (1 === preg_match('/Windows NT|Mac OS X|Linux/i', $userAgent, $m)) {
            $host = $m[0];
        }

        return sprintf('Outlook Add-in (%s)', $host);
    }
}
