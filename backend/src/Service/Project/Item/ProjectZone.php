<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * The time zone a project's times are read and said in: the project's own
 * zone, else the owner's profile zone, else UTC.
 */
final readonly class ProjectZone
{
    public const FALLBACK = 'UTC';

    public function __construct(private UserRepository $users)
    {
    }

    public function of(Project $project): \DateTimeZone
    {
        $stored = $project->getTimezone();
        if (null !== $stored && self::isValid($stored)) {
            return new \DateTimeZone($stored);
        }
        $owner = $this->users->find($project->getUserId());
        $profile = $owner instanceof User ? ($owner->getUserDetails()['timezone'] ?? null) : null;

        return new \DateTimeZone(\is_string($profile) && self::isValid($profile) ? $profile : self::FALLBACK);
    }

    public static function isValid(string $zone): bool
    {
        return \in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }
}
