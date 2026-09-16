<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceRef;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Attributes IAM_READ / IAM_USE / IAM_EDIT / IAM_MANAGE on a {@see ResourceRef}.
 *
 * No controller adopts this voter in S1 — S2 migrates ChatController and /files
 * group routes kind by kind.
 *
 * @extends Voter<string, ResourceRef>
 */
final class IamVoter extends Voter
{
    public const READ = 'IAM_READ';
    public const USE = 'IAM_USE';
    public const EDIT = 'IAM_EDIT';
    public const MANAGE = 'IAM_MANAGE';

    public function __construct(
        private readonly AccessGate $accessGate,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ResourceRef && null !== self::toPermission($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $permission = self::toPermission($attribute);
        if (null === $permission) {
            return false;
        }

        return $this->accessGate->decide($user, $subject->kind, $subject->id, $permission);
    }

    private static function toPermission(string $attribute): ?Permission
    {
        return match ($attribute) {
            self::READ => Permission::Read,
            self::USE => Permission::Use,
            self::EDIT => Permission::Edit,
            self::MANAGE => Permission::Manage,
            default => null,
        };
    }
}
