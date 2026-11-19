<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\User;
use App\Service\Iam\UserLifecycleService;

/**
 * Creates the first administrator of an empty installation and gives the
 * installation its closed defaults ({@see FreshInstallDefaults}).
 *
 * Both first-admin paths use it: the setup wizard and the BOOTSTRAP_ADMIN_*
 * start. The caller holds the first-admin lock and has checked that no
 * administrator exists yet.
 */
final readonly class FirstAdminCreator
{
    public function __construct(
        private UserLifecycleService $userLifecycleService,
        private FreshInstallDefaults $freshInstallDefaults,
    ) {
    }

    public function create(
        string $email,
        #[\SensitiveParameter]
        string $plainPassword,
    ): User {
        $user = $this->userLifecycleService->createUser(
            email: $email,
            plainPassword: $plainPassword,
            userLevel: 'ADMIN',
            // No mailbox round trip is possible yet — the mailer may well be
            // one of the things this administrator is about to configure —
            // and there is no other account this could grant access to.
            emailVerified: true,
        );

        $this->freshInstallDefaults->apply();

        return $user;
    }
}
