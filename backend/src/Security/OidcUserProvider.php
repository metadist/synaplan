<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * OIDC User Provider
 * 
 * Creates or updates users from OIDC user data
 */
class OidcUserProvider
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Load or create user from OIDC data
     */
    public function loadUserFromOidcData(array $oidcData): User
    {
        $email = $oidcData['email'] ?? null;
        $sub = $oidcData['sub'] ?? null;

        if (!$email && !$sub) {
            throw new \RuntimeException('OIDC data must contain email or sub claim');
        }

        // Try to find existing user by email
        $user = $email ? $this->userRepository->findOneBy(['mail' => $email]) : null;

        // Or by OIDC sub in userDetails
        if (!$user && $sub) {
            $sql = "SELECT BID FROM BUSER WHERE JSON_EXTRACT(BUSERDETAILS, '$.oidc_sub') = :sub LIMIT 1";
            $stmt = $this->em->getConnection()->prepare($sql);
            $result = $stmt->executeQuery(['sub' => $sub]);
            $userId = $result->fetchOne();

            if ($userId) {
                $user = $this->userRepository->find($userId);
            }
        }

        if ($user) {
            // Update existing user with fresh OIDC data
            $this->updateUserFromOidcData($user, $oidcData);
        } else {
            // Create new user from OIDC data
            $user = $this->createUserFromOidcData($oidcData);
        }

        $this->em->flush();

        return $user;
    }

    /**
     * Create new user from OIDC data
     */
    private function createUserFromOidcData(array $oidcData): User
    {
        $user = new User();
        
        $email = $oidcData['email'] ?? 'oidc_' . bin2hex(random_bytes(8)) . '@synaplan.local';
        $user->setMail($email);
        $user->setPw(''); // No password for OIDC users
        $user->setType('OIDC');
        $user->setUserLevel('NEW'); // Default level, can be upgraded

        $details = [
            'oidc_sub' => $oidcData['sub'] ?? null,
            'firstName' => $oidcData['given_name'] ?? '',
            'lastName' => $oidcData['family_name'] ?? '',
            'fullName' => $oidcData['name'] ?? '',
            'username' => $oidcData['preferred_username'] ?? '',
            'email' => $oidcData['email'] ?? '',
            'email_verified' => $oidcData['email_verified'] ?? false,
            'created_via' => 'oidc',
            'oidc_last_login' => date('Y-m-d H:i:s'),
        ];

        $user->setUserDetails($details);

        $this->em->persist($user);

        $this->logger->info('Created new user from OIDC', [
            'email' => $email,
            'sub' => $oidcData['sub'] ?? null,
        ]);

        return $user;
    }

    /**
     * Update existing user with OIDC data
     */
    private function updateUserFromOidcData(User $user, array $oidcData): void
    {
        $details = $user->getUserDetails();

        // Update OIDC-specific fields
        $details['oidc_sub'] = $oidcData['sub'] ?? $details['oidc_sub'] ?? null;
        $details['oidc_last_login'] = date('Y-m-d H:i:s');

        // Update user info if provided
        if (isset($oidcData['given_name'])) {
            $details['firstName'] = $oidcData['given_name'];
        }
        if (isset($oidcData['family_name'])) {
            $details['lastName'] = $oidcData['family_name'];
        }
        if (isset($oidcData['name'])) {
            $details['fullName'] = $oidcData['name'];
        }
        if (isset($oidcData['email'])) {
            $details['email'] = $oidcData['email'];
            // Update primary email if changed
            if ($user->getMail() !== $oidcData['email']) {
                $user->setMail($oidcData['email']);
            }
        }

        $user->setUserDetails($details);

        $this->logger->info('Updated user from OIDC', [
            'user_id' => $user->getId(),
            'email' => $user->getMail(),
        ]);
    }
}

