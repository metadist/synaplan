<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OidcUserService;
use Psr\Log\LoggerInterface;

/**
 * Finds the Synaplan account of every signed-in meeting participant (email
 * and Keycloak subject from their Jitsi token). Someone who never opened
 * Synaplan gets their account created the way their first sign-in would, so
 * the transcript is waiting for them. Guests (no token) are skipped.
 *
 * Existing accounts are only looked up, never updated: a delivery must not
 * touch anyone's profile, roles or groups.
 */
final readonly class ParticipantResolver
{
    private const PROVIDER = 'keycloak';

    public function __construct(
        private UserRepository $users,
        private OidcUserService $oidcUsers,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $attendees
     *
     * @return array<int, User> user id => account
     */
    public function accounts(array $attendees): array
    {
        $accounts = [];
        foreach ($attendees as $attendee) {
            $email = trim((string) ($attendee['email'] ?? ''));
            $sub = trim((string) ($attendee['sub'] ?? ''));
            if ('' === $email || '' === $sub) {
                continue;
            }

            $user = $this->users->findOneBy(['mail' => $email]) ?? $this->users->findOneBy(['mail' => mb_strtolower($email)]);
            if ($user instanceof User) {
                if (self::PROVIDER !== $user->getProviderId() || !$this->sameSubject($user, $sub)) {
                    $this->logger->warning('synascriber: participant not matched to an account', ['user' => $user->getId()]);
                    continue;
                }
            } else {
                $user = $this->provision($sub, $email, (string) ($attendee['name'] ?? ''));
            }

            if ($user instanceof User && null !== $user->getId()) {
                $accounts[(int) $user->getId()] = $user;
            }
        }

        return $accounts;
    }

    private function provision(string $sub, string $email, string $name): ?User
    {
        $claims = [
            'sub' => $sub,
            'email' => $email,
            'email_verified' => true,
            'preferred_username' => explode('@', $email, 2)[0],
        ];
        if ('' !== trim($name)) {
            $claims['name'] = trim($name);
        }

        try {
            $user = $this->oidcUsers->findOrCreateFromClaims($claims);
        } catch (\Throwable $e) {
            $this->logger->warning('synascriber: could not create the account of a participant', ['error' => $e->getMessage()]);

            return null;
        }
        $this->logger->info('synascriber: account created for a meeting participant', ['user' => $user->getId()]);

        return $user;
    }

    private function sameSubject(User $user, string $sub): bool
    {
        $known = $user->getUserDetails()['oidc_sub'] ?? null;

        return !is_string($known) || '' === $known || $known === $sub;
    }
}
