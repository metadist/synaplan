<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * A new sign-in email is stored only after the person opened a one-time link
 * sent to that address. Until then the account keeps its current address, so
 * nobody can point an account at a mailbox they do not read.
 *
 * The address waiting for its link lives in BUSERDETAILS. The link works
 * once, for an hour; only its hash is stored.
 */
final readonly class EmailChange
{
    public const TOKEN_TYPE = 'email_change';
    public const TTL_SECONDS = 3600;
    public const DETAILS_KEY = 'pending_email';

    public function __construct(
        private UserRepository $users,
        private VerificationTokenRepository $tokens,
        private EmailChangeMailer $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Another spelling of the same inbox is stored at once. Any other address
     * waits for its link, which replaces a link asked for earlier.
     *
     * @return string|null the address that now waits for its link
     */
    public function request(User $user, string $email): ?string
    {
        if (mb_strtolower(trim($user->getMail())) === $email) {
            $user->setMail($email);
            $this->users->save($user);

            return $this->pending($user);
        }

        $details = $user->getUserDetails();
        $details[self::DETAILS_KEY] = ['address' => $email, 'expires' => time() + self::TTL_SECONDS];
        $user->setUserDetails($details);
        $this->users->save($user);

        $this->tokens->deleteForUser((int) $user->getId(), self::TOKEN_TYPE);
        $secret = $this->tokens->createHashedToken($user, self::TOKEN_TYPE, self::TTL_SECONDS);
        $this->mailer->send($email, $secret, $user->getLocale());
        $this->logger->info('Sign-in email change waits for its confirmation link', ['user_id' => $user->getId()]);

        return $email;
    }

    /**
     * The address whose link can still be opened, or null.
     */
    public function pending(User $user): ?string
    {
        $pending = $user->getUserDetails()[self::DETAILS_KEY] ?? null;
        if (!is_array($pending) || !is_string($pending['address'] ?? null) || !is_int($pending['expires'] ?? null)) {
            return null;
        }

        return $pending['expires'] > time() ? $pending['address'] : null;
    }

    public function cancel(User $user): void
    {
        $this->tokens->deleteForUser((int) $user->getId(), self::TOKEN_TYPE);
        $this->forget($user);
        $this->users->save($user);
    }

    /**
     * The address the account now signs in with, or null when the link is
     * unknown, expired, already used, or the address was taken meanwhile.
     */
    public function confirm(string $secret): ?string
    {
        $token = $this->tokens->findValidHashedToken(trim($secret), self::TOKEN_TYPE);
        if (null === $token || !$this->tokens->claimUnused($token)) {
            return null;
        }

        $user = $token->getUser();
        $address = $this->pending($user);
        $this->forget($user);
        if (null === $address || !$user->canSignIn() || !$user->canChangePassword()) {
            $this->users->save($user);

            return null;
        }
        if (null !== $this->users->findOtherByNormalizedEmail($address, (int) $user->getId())) {
            $this->users->save($user);

            return null;
        }

        $user->setMail($address);
        try {
            $this->users->save($user);
        } catch (UniqueConstraintViolationException) {
            // The check above is not atomic. UNIQ_BUSER_BMAIL decides a tie.
            return null;
        }

        // Reset links mailed to the previous address stop working with it.
        $this->tokens->deleteForUser((int) $user->getId(), PasswordResetRequest::TOKEN_TYPE);
        $this->logger->info('Sign-in email changed after its confirmation link was opened', ['user_id' => $user->getId()]);

        return $address;
    }

    private function forget(User $user): void
    {
        $details = $user->getUserDetails();
        unset($details[self::DETAILS_KEY]);
        $user->setUserDetails($details);
    }
}
