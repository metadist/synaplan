<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Account creation through an OAuth provider (Google, GitHub, Apple) gets the
 * same protection as the email form.
 *
 * The browser passes the human-check token to the provider's `/login` start
 * endpoint as `?challenge=`; {@see stateExtras()} verifies it and records the
 * result in the signed OAuth state. On the callback, a *new* account is
 * refused unless that proof is present, its nonce has not been used yet, and
 * the signup limits allow it. An existing account — matched by email or by
 * the provider's stable id — always signs in.
 *
 * `?native=true` on the browser start URL only chooses the deep-link return
 * path. It does not skip the human check: anyone can add that query flag.
 * The only exemption is Sign in with Apple from the iOS sheet
 * ({@see assertAppleSignup()} with `$verifiedNative`), because that request
 * already carries an Apple-signed identity token. It still counts against
 * the signup limits.
 *
 * One solved check must not mint many accounts. The state's nonce is spent
 * in the shared rate-limiter cache the first time it authorises a callback,
 * so a replay fails on every node.
 */
final readonly class OAuthSignupGate
{
    public const STATE_HUMAN = 'human';

    public function __construct(
        private SignupProtection $protection,
        private UserRepository $users,
        private ModelConfigService $modelConfig,
        private RateLimiterFactoryInterface $oauthSignupNonceLimiter,
        private LoggerInterface $logger,
        private SignupAdmission $admission,
    ) {
    }

    /**
     * Extra fields for the signed state at the start of the flow.
     *
     * @return array<string, mixed>
     */
    public function stateExtras(Request $request, string $provider): array
    {
        $extras = [];
        $plan = strtoupper(trim((string) $request->query->get('plan', '')));
        if ('' !== $plan) {
            $extras['plan'] = $plan;
        }
        $code = trim((string) $request->query->get('code', ''));
        if ('' !== $code) {
            $extras['code'] = $code;
        }

        if (!$this->protection->challengeRequired()) {
            return $extras;
        }

        $token = (string) $request->query->get('challenge', '');
        if ($this->protection->verifyHuman($token, 'signup', $request->getClientIp())) {
            return $extras + [self::STATE_HUMAN => true];
        }

        $this->logger->info('OAuth start without a passed human check; a new account will be refused on the callback', [
            'provider' => $provider,
        ]);

        return $extras;
    }

    /**
     * Google's email is stable and always present for a real consent.
     *
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    public function assertGoogleSignup(?string $email, array $statePayload, ?string $clientIp): void
    {
        $existing = null !== $email && '' !== $email ? $this->users->findOneBy(['mail' => $email]) : null;
        $this->assertNewAccountAllowed($existing, $statePayload, $clientIp, false);
    }

    /**
     * GitHub matches an existing account by email or, when the address is
     * hidden, by the numeric GitHub id.
     *
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    public function assertGithubSignup(?string $email, int|string|null $githubId, array $statePayload, ?string $clientIp): void
    {
        $existing = null !== $email && '' !== $email ? $this->users->findOneBy(['mail' => $email]) : null;
        if (null === $existing && null !== $githubId && '' !== (string) $githubId) {
            $existing = $this->users->findByGithubId($githubId);
        }
        $this->assertNewAccountAllowed($existing, $statePayload, $clientIp, false);
    }

    /**
     * Apple's durable key is `sub`. The email is absent on later logins and
     * the private-relay address can change, so an email-only check would
     * treat a returning person as a new account and refuse them when sign-up
     * is paused or the limit is spent.
     *
     * `$verifiedNative` is true only for the iOS sheet endpoint, which has
     * already verified an Apple identity token. A `native` flag inside the
     * browser OAuth state is not that proof.
     *
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    public function assertAppleSignup(string $appleSub, ?string $email, array $statePayload, ?string $clientIp, bool $verifiedNative = false): void
    {
        $existing = $this->users->findByAppleSub($appleSub);
        if (null === $existing && null !== $email && '' !== $email) {
            $existing = $this->users->findOneBy(['mail' => $email]);
        }
        $this->assertNewAccountAllowed($existing, $statePayload, $clientIp, $verifiedNative);
    }

    /**
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    private function assertNewAccountAllowed(?User $existing, array $statePayload, ?string $clientIp, bool $verifiedNative): void
    {
        if (!$verifiedNative) {
            $this->consumeNonce($statePayload, null === $existing);
        }
        if (null !== $existing) {
            return;
        }
        if (!$verifiedNative && $this->protection->challengeRequired() && true !== ($statePayload[self::STATE_HUMAN] ?? false)) {
            throw SignupBlockedException::challengeRequired();
        }

        $this->protection->assertSignupAllowed($clientIp);
        $this->admission->resolve($this->stateString($statePayload, 'plan'), $this->stateString($statePayload, 'code'));
    }

    private function stateString(array $state, string $key): ?string
    {
        $value = $state[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Spend the OAuth state nonce once. A missing nonce only blocks account
     * creation; a returning account can still sign in.
     *
     * @param array<string, mixed> $statePayload
     *
     * @throws SignupBlockedException
     */
    private function consumeNonce(array $statePayload, bool $required): void
    {
        $nonce = $statePayload['nonce'] ?? null;
        if (!is_string($nonce) || '' === $nonce) {
            if ($required) {
                throw SignupBlockedException::challengeRequired();
            }

            return;
        }
        if (!$this->oauthSignupNonceLimiter->create($nonce)->consume()->isAccepted()) {
            $this->logger->warning('OAuth signup state replay refused');

            throw SignupBlockedException::challengeRequired();
        }
    }

    /**
     * Model defaults, and in admission mode the plan or the invitation code
     * carried in the OAuth state. Open mode ignores that state.
     *
     * @param array<string, mixed> $state
     */
    public function completeNewUser(User $user, array $state = []): void
    {
        $admission = $this->admission->resolve($this->stateString($state, 'plan'), $this->stateString($state, 'code'));
        if (null !== $admission) {
            $this->admission->apply($user, $admission);
            if ($admission['code'] instanceof \App\Entity\SignupCode) {
                $this->admission->commitCode($user, $admission['code']);
            }
        }

        $this->modelConfig->initializeNewUserDefaults((int) $user->getId());
    }
}
