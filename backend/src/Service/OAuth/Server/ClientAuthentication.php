<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Service\Http\ClientAddress;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Who is calling an OAuth endpoint as a client, proven by a client assertion
 * ({@see ClientAssertionVerifier}). Failed attempts count per address, and
 * an address that keeps failing has to wait: client ids are not secret, so
 * without the brake anyone could try assertions against them at will.
 */
final readonly class ClientAuthentication
{
    /** The limiter's window (config/packages/rate_limiter.yaml), in seconds. */
    private const RETRY_AFTER = 60;

    public function __construct(
        private ClientAssertionVerifier $assertions,
        private RateLimiterFactoryInterface $oauthClientAuthFailuresLimiter,
        #[Autowire('%env(APP_URL)%')]
        private string $issuer,
    ) {
    }

    /**
     * The client of a form request (token, revocation, pushed authorization):
     * client_assertion and client_assertion_type in the body.
     *
     * @param string $path the endpoint's path, accepted as audience besides the issuer
     *
     * @throws ClientAuthenticationException
     */
    public function fromForm(Request $request, string $path): PlatformInstance
    {
        $this->assertNotThrottled($request);
        $instance = ClientAssertionVerifier::TYPE === $request->request->get('client_assertion_type')
            ? $this->assertions->verify((string) $request->request->get('client_assertion', ''), [$this->url($path)])
            : null;
        $claimed = (string) $request->request->get('client_id', '');
        if (!$instance instanceof PlatformInstance || ('' !== $claimed && $claimed !== $instance->getInstanceId())) {
            throw $this->failed($request);
        }

        return $instance;
    }

    /**
     * The client of a request about one client (its configuration, its
     * people): the assertion is the bearer token and must be $clientId's.
     *
     * @param list<string> $paths     endpoint paths accepted as audience besides the issuer
     * @param bool         $pendingOk whether a client still waiting for approval may authenticate
     *
     * @throws ClientAuthenticationException
     */
    public function fromBearer(Request $request, string $clientId, array $paths, bool $pendingOk = false): PlatformInstance
    {
        $this->assertNotThrottled($request);
        $header = (string) $request->headers->get('Authorization', '');
        $instance = str_starts_with($header, 'Bearer ')
            ? $this->assertions->verify(trim(substr($header, 7)), array_map($this->url(...), $paths), $pendingOk)
            : null;
        if (!$instance instanceof PlatformInstance || $instance->getInstanceId() !== $clientId) {
            throw $this->failed($request);
        }

        return $instance;
    }

    /**
     * @throws ClientAuthenticationException when the caller's address has to wait
     */
    public function assertNotThrottled(Request $request): void
    {
        // consume(0) reads the window without counting this call.
        $limit = $this->oauthClientAuthFailuresLimiter->create($this->key($request))->consume(0);
        if ($limit->getRemainingTokens() <= 0) {
            throw new ClientAuthenticationException(self::RETRY_AFTER);
        }
    }

    /**
     * Counts a failed authentication; returns what to throw for it.
     */
    public function failed(Request $request): ClientAuthenticationException
    {
        $this->oauthClientAuthFailuresLimiter->create($this->key($request))->consume();

        return new ClientAuthenticationException();
    }

    private function key(Request $request): string
    {
        return ClientAddress::resolve($request) ?? 'unknown';
    }

    private function url(string $path): string
    {
        return rtrim($this->issuer, '/').$path;
    }
}
