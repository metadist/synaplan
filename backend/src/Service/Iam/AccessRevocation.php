<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Auth\EmailLinkSignIn;
use App\Service\Auth\PasswordResetRequest;
use App\Service\Auth\TokenService;

/**
 * Ends the ways into an account that were handed out earlier: browser
 * sessions, cloud grants, mailed links and API keys.
 */
final readonly class AccessRevocation
{
    public const EMAIL_VERIFICATION_TOKEN = 'email_verification';

    private const MAILED_TOKEN_TYPES = [
        self::EMAIL_VERIFICATION_TOKEN,
        PasswordResetRequest::TOKEN_TYPE,
        EmailLinkSignIn::TOKEN_TYPE,
    ];

    public function __construct(
        private TokenService $tokenService,
        private OAuthTokenRepository $oauthTokens,
        private VerificationTokenRepository $verificationTokens,
        private ApiKeyRepository $apiKeys,
    ) {
    }

    /**
     * Every refresh token dies, and so does every grant a cloud holds.
     */
    public function endSessions(User $user): void
    {
        $this->tokenService->revokeAllUserTokens($user);
        $this->endCloudGrants($user);
    }

    public function endCloudGrants(User $user): void
    {
        $id = $user->getId();
        if (null !== $id) {
            $this->oauthTokens->revokeAllForUser($id);
        }
    }

    /**
     * Drops what whoever opened the account could still hold without ever
     * having read its mailbox: mailed links that were not opened and API keys.
     *
     * @return int the number of API keys removed
     */
    public function discardUnprovenAccess(User $user): int
    {
        $id = $user->getId();
        if (null === $id) {
            return 0;
        }

        foreach (self::MAILED_TOKEN_TYPES as $type) {
            $this->verificationTokens->deleteForUser($id, $type);
        }

        $keys = $this->apiKeys->findByOwner($id);
        foreach ($keys as $key) {
            $this->apiKeys->remove($key);
        }

        return count($keys);
    }
}
