<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Account creation through an OAuth provider (Google, GitHub, Apple) gets the
 * same protection as the email form.
 *
 * The browser passes the human-check token to the provider's `/login` start
 * endpoint as `?challenge=`; {@see stateExtras()} verifies it and records the
 * result in the signed OAuth state. On the callback,
 * {@see assertNewAccountAllowed()} lets every existing account sign in and
 * refuses a *new* one unless the state carries that proof and the signup
 * limits allow it. 84 % of the fake accounts of 2026-10-24 came through
 * "Sign in with Google", which had no check at all.
 *
 * MOBILE-APP SEAM: the native apps cannot run a browser challenge, so a
 * native start skips the human check and keeps only the rate limits.
 */
final readonly class OAuthSignupGate
{
    public const STATE_HUMAN = 'human';

    public function __construct(
        private SignupProtection $protection,
        private UserRepository $users,
        private ModelConfigService $modelConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extra fields for the signed state at the start of the flow.
     *
     * @return array<string, bool>
     */
    public function stateExtras(Request $request, string $provider): array
    {
        if (!$this->protection->challengeRequired()) {
            return [];
        }

        $token = (string) $request->query->get('challenge', '');
        if ($this->protection->verifyHuman($token, 'signup', $request->getClientIp())) {
            return [self::STATE_HUMAN => true];
        }

        $this->logger->info('OAuth start without a passed human check; a new account will be refused on the callback', [
            'provider' => $provider,
        ]);

        return [];
    }

    /**
     * Refuse the creation of a new account unless the start of the flow passed
     * the human check (web only) and the signup limits allow it. An existing
     * account always passes.
     *
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    public function assertNewAccountAllowed(?string $email, array $statePayload, ?string $clientIp, bool $native = false): void
    {
        if (null !== $email && '' !== $email && null !== $this->users->findOneBy(['mail' => $email])) {
            return;
        }

        if (!$native && $this->protection->challengeRequired() && true !== ($statePayload[self::STATE_HUMAN] ?? false)) {
            throw SignupBlockedException::challengeRequired();
        }

        $this->protection->assertSignupAllowed($clientIp);
    }

    /** Everything a freshly created account needs besides its row. */
    public function completeNewUser(User $user): void
    {
        $this->modelConfig->initializeNewUserDefaults((int) $user->getId());
    }
}
