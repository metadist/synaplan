<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Short-lived, read-only credential for loading a user's own media.
 *
 * MOBILE-APP SEAM (Epic 7). An `<img>`, `<audio>` or `<video>` element cannot
 * send an `Authorization` header, and inside the native shell there is no
 * cookie either — the WebView runs on `capacitor://localhost`, cross-origin to
 * the backend. The credential therefore has to travel in the URL.
 *
 * Putting the session access token there (what `QueryTokenAuthenticator`
 * accepts) means a URL that leaks through a log, a screenshot or a `Referer`
 * hands over the whole session. This token instead carries a single purpose:
 * it only ever identifies the owner for a media read, is verified explicitly by
 * the serving controllers rather than by a firewall authenticator, and so can
 * never authenticate anything else.
 *
 * The signing key is derived from `APP_SECRET` rather than being it, so a
 * forged media token cannot be built from — and does not help forge — any other
 * `APP_SECRET`-signed credential.
 *
 * Deliberately not single-use: a media URL is re-fetched on seek, range
 * request, cache revalidation and retry.
 */
final readonly class MediaAccessTokenService
{
    /**
     * Query parameter carrying the token.
     *
     * Must NOT be `token`: that name is claimed by `QueryTokenAuthenticator`
     * on the `api` firewall, which would reject the request with 401 before
     * the controller ever runs.
     */
    public const QUERY_PARAM = 'media_token';

    /**
     * Long enough that reading a chat, scrolling back or resuming from the
     * background never trips over it, short enough that a leaked URL dies on
     * its own.
     */
    public const TTL = 1800;

    private const PURPOSE = 'media_read';

    public function __construct(
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $appSecret,
    ) {
    }

    /**
     * Resolve the user a request's media token grants read access for.
     *
     * The single entry point for the serving controllers, so none of them can
     * accidentally skip the purpose or expiry check.
     */
    public function resolveUser(Request $request): ?User
    {
        $token = $request->query->get(self::QUERY_PARAM);
        if (!is_string($token) || '' === $token) {
            return null;
        }

        $userId = $this->resolveUserId($token);

        return null !== $userId ? $this->userRepository->find($userId) : null;
    }

    public function generate(User $user): string
    {
        $payload = [
            'uid' => $user->getId(),
            'purpose' => self::PURPOSE,
            'exp' => time() + self::TTL,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->encode($json).'.'.$this->sign($json);
    }

    /**
     * Return the user id a media token grants read access for, or null on any
     * failure (bad format, bad signature, wrong purpose, expired).
     */
    public function resolveUserId(string $token): ?int
    {
        $parts = explode('.', $token);
        if (2 !== count($parts)) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        $json = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        if (false === $json || '' === $json) {
            return null;
        }

        if (!hash_equals($this->sign($json), $signature)) {
            $this->logger->warning('Media access token signature verification failed');

            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (self::PURPOSE !== ($payload['purpose'] ?? null)) {
            return null;
        }

        $expiresAt = $payload['exp'] ?? null;
        if (!is_int($expiresAt) || $expiresAt < time()) {
            return null;
        }

        $uid = $payload['uid'] ?? null;

        return is_int($uid) ? $uid : null;
    }

    private function encode(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function sign(string $json): string
    {
        return hash_hmac('sha256', $json, hash_hmac('sha256', 'media-access-token/v1', $this->appSecret));
    }
}
