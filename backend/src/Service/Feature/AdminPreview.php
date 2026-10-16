<?php

declare(strict_types=1);

namespace App\Service\Feature;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Code-based preview. A feature listed in {@see FEATURES} exists only for
 * admins (`ROLE_ADMIN`, including an admin whose role comes from OIDC).
 * Everyone else gets the same answer as if the feature did not exist.
 *
 * There is no operator toggle. Release a feature by removing its id from
 * {@see FEATURES} and every `#[AdminPreview]`, `allows()` / `allowsUserId()`
 * and frontend `isAdminPreview()` / `<AdminPreview>` that names it, in the
 * same change. A call site left behind fails loudly instead of opening the
 * feature by accident.
 */
final readonly class AdminPreview
{
    /** @var list<string> */
    public const FEATURES = [];

    /**
     * @param list<string> $features tests pass their own ids; production uses {@see FEATURES}
     */
    public function __construct(
        private UserRepository $users,
        private array $features = self::FEATURES,
    ) {
    }

    public function allows(string $feature, ?UserInterface $user): bool
    {
        $this->assertKnown($feature);
        if (null === $user) {
            return false;
        }

        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * For worker and webhook code, where there is no session.
     */
    public function allowsUserId(string $feature, int $userId): bool
    {
        $user = $this->users->find($userId);

        return $user instanceof User && $this->allows($feature, $user);
    }

    private function assertKnown(string $feature): void
    {
        if (!\in_array($feature, $this->features, true)) {
            throw new \LogicException(sprintf('Unknown admin-preview feature "%s". Add it to %s::FEATURES or remove the call site.', $feature, self::class));
        }
    }
}
