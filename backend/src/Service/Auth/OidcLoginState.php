<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

/**
 * The OIDC login attempt behind the short state sent to the identity provider.
 *
 * Keycloak copies that value into the redirect Location and into its
 * KC_RESTART cookie. A signed blob of a few hundred characters — the PKCE
 * verifier, the scopes, the signature — pushes those response headers over
 * the 4 kB buffer of a default nginx, and the login ends on a 502.
 *
 * The URL therefore carries only a random id. The verifier and the rest of
 * the attempt wait in the shared cache for ten minutes, the same lifetime the
 * signed state used to have, and are spent on the first callback. The cache
 * and the lock have to be the shared ones: the callback may be served by a
 * different web node than the login that started the attempt.
 *
 * An attempt started in a browser is spent only by that browser
 * ({@see LoginBrowserBinding}). One the native app started ends in a handoff
 * to the app and is not bound here.
 */
final readonly class OidcLoginState
{
    public const TTL_SECONDS = 600;

    public const ID_PATTERN = '[a-f0-9]{32}';

    private const CACHE_PREFIX = 'oidc_login_';
    private const PROVIDER = 'keycloak';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
        private LoginBrowserBinding $browser,
    ) {
    }

    /**
     * Store one login attempt and return the id that goes into the authorize URL.
     * A browser attempt also puts its cookie on the response to `$request`.
     *
     * @throws \InvalidArgumentException when the verifier or the scopes cannot be stored
     * @throws \RuntimeException         when the cache does not accept the attempt
     */
    public function issue(Request $request, string $codeVerifier, string $scopes, bool $native, ?string $language): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $codeVerifier)) {
            throw new \InvalidArgumentException('PKCE verifier is not usable.');
        }
        if ('' === $scopes || strlen($scopes) > 512) {
            throw new \InvalidArgumentException('OIDC scopes are not usable.');
        }

        $state = bin2hex(random_bytes(16));
        $item = $this->cache->getItem($this->cacheKey($state));
        $item->set([
            'pkce_verifier' => $codeVerifier,
            'scopes' => $scopes,
            'native' => $native,
            'language' => $this->language($language),
            'browser' => $native ? null : $this->browser->start($request, self::PROVIDER),
        ]);
        $item->expiresAfter(self::TTL_SECONDS);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('The OIDC login attempt could not be stored.');
        }

        return $state;
    }

    /**
     * Read the attempt and forget it. A second callback, one that never
     * started here, or one in another browser than the attempt's gets nothing.
     *
     * @return array{pkce_verifier: string, scopes: string, native: bool, language: string|null}|null
     */
    public function consume(Request $request, string $state): ?array
    {
        if (1 !== preg_match('/^'.self::ID_PATTERN.'$/', $state)) {
            return null;
        }

        $key = $this->cacheKey($state);
        // Do not block. The other callback is already redeeming this attempt,
        // and reading the verifier beside it would hand the same secret out twice.
        $lock = $this->lockFactory->createLock($key, 30.0);
        if (!$lock->acquire(false)) {
            return null;
        }

        try {
            $item = $this->cache->getItem($key);
            if (!$item->isHit()) {
                return null;
            }

            $stored = $item->get();
            // A failed delete leaves the attempt readable. Handing out the
            // verifier anyway would let the next callback redeem it too.
            if (!$this->cache->deleteItem($key)) {
                return null;
            }

            return $this->payload($stored, $request);
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(string $state): string
    {
        return self::CACHE_PREFIX.hash('sha256', $state);
    }

    private function language(?string $language): ?string
    {
        if (null === $language || 1 !== preg_match('/^[a-z]{2}$/', $language)) {
            return null;
        }

        return $language;
    }

    /**
     * @return array{pkce_verifier: string, scopes: string, native: bool, language: string|null}|null
     */
    private function payload(mixed $stored, Request $request): ?array
    {
        if (!is_array($stored)) {
            return null;
        }

        $verifier = $stored['pkce_verifier'] ?? null;
        $scopes = $stored['scopes'] ?? null;
        $native = $stored['native'] ?? null;
        $language = $stored['language'] ?? null;
        if (!is_string($verifier) || !is_string($scopes) || !is_bool($native)) {
            return null;
        }
        if (null !== $language && !is_string($language)) {
            return null;
        }
        if (!$native && !$this->browser->confirm($request, self::PROVIDER, $stored['browser'] ?? null)) {
            return null;
        }

        return [
            'pkce_verifier' => $verifier,
            'scopes' => $scopes,
            'native' => $native,
            'language' => $language,
        ];
    }
}
