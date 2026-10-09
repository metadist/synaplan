<?php

declare(strict_types=1);

namespace App\Service\Profile;

/**
 * Ids of the guided tours (and dismissed onboarding cards) a person has
 * finished, stored in BUSERDETAILS so the state follows the account across
 * devices. Ids come from the frontend tour registry, so only a short,
 * bounded list of slug-like strings is accepted.
 */
final class ToursSeen
{
    public const DETAILS_KEY = 'tours_seen';
    public const MAX_IDS = 64;
    public const MAX_ID_LENGTH = 64;
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /**
     * @param array<string, mixed> $details
     *
     * @return list<string>
     */
    public static function fromDetails(array $details): array
    {
        return self::normalize($details[self::DETAILS_KEY] ?? []);
    }

    /**
     * Drops anything that is not a valid id, removes duplicates and keeps
     * the newest MAX_IDS entries.
     *
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = strtolower(trim($id));
            if ('' === $id || strlen($id) > self::MAX_ID_LENGTH || 1 !== preg_match(self::ID_PATTERN, $id)) {
                continue;
            }
            $ids[$id] = true;
        }

        return array_slice(array_keys($ids), -self::MAX_IDS);
    }
}
