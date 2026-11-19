<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * Which zone a wall-clock event time belongs to. A wall-clock time is
 * meaningless without its zone.
 *
 * Precedence:
 *   1. A zone the user named outright in THIS message (IANA identifier or
 *      bare UTC/GMT token) — wins for this event, even over a stored profile
 *      zone and whatever the planner emitted.
 *   2. A planner zone corroborated by the user's own words (e.g. the city it
 *      is named after, "10 New York").
 *   3. The stored profile timezone — authoritative, beats any planner guess.
 *   4. The planner timezone, when valid and not UTC (country inference).
 *   5. Otherwise no zone can be trusted and the event is NOT written: the
 *      calendar step asks for the timezone instead of stamping silent server
 *      UTC (#2010).
 */
final class EventTimezone
{
    /**
     * Returns the zone, its name, and whether an explicit offset in start/end
     * must be reinterpreted as wall-clock in that zone — or null when no zone
     * can be trusted and the event must not be written.
     *
     * @param string      $plannerZone the zone the plan carries, possibly empty or invalid
     * @param string|null $profileZone the person's stored zone, already validated ({@see valid()})
     *
     * @return array{0: \DateTimeZone, 1: string, 2: bool}|null
     */
    public static function resolve(string $plannerZone, string $messageText, ?string $profileZone): ?array
    {
        $plannerTz = self::valid($plannerZone);

        // 1. A zone the user named outright (IANA identifier or bare UTC/GMT
        // token) wins even when the planner emitted something else.
        $namedTz = self::namedIn($messageText);
        if (null !== $namedTz && $namedTz !== $profileZone) {
            return [new \DateTimeZone($namedTz), $namedTz, false];
        }
        // 2. A planner zone corroborated by the user's own words (e.g. the
        // city it is named after) wins for this event.
        if (null !== $plannerTz && $plannerTz !== $profileZone && self::isNamedBy($plannerTz, $messageText)) {
            return [new \DateTimeZone($plannerTz), $plannerTz, false];
        }
        // 3. The stored profile zone is authoritative.
        if (null !== $profileZone) {
            return [new \DateTimeZone($profileZone), $profileZone, true];
        }
        // 4. A planner zone that is not a silent UTC default (country inference).
        if (null !== $plannerTz && 'UTC' !== strtoupper($plannerTz)) {
            return [new \DateTimeZone($plannerTz), $plannerTz, false];
        }

        // 5. Server time only — never stamp it onto a wall-clock request.
        return null;
    }

    /**
     * The canonical name of a zone, or null when the name is no zone.
     */
    public static function valid(string $name): ?string
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }
        try {
            new \DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }

        return self::canonical($name) ?? $name;
    }

    public static function parse(string $value, \DateTimeZone $zone, bool $stripExplicitOffset): \DateTimeImmutable
    {
        $value = trim($value);
        if ($stripExplicitOffset) {
            // new \DateTimeImmutable($str, $tz) ignores $tz when $str already
            // carries Z or an offset — a planner "…T10:00:00Z" must not
            // override the stored profile zone, so the wall clock is
            // reinterpreted in it.
            $value = trim((string) preg_replace('/(?:Z|[+-]\d{2}:?\d{2})\s*$/i', '', $value));
        }
        if ('' === $value) {
            throw new \InvalidArgumentException('empty datetime');
        }

        return new \DateTimeImmutable($value, $zone);
    }

    /**
     * A zone the user named outright in their own message: a bare UTC/GMT
     * token or an IANA identifier. Unlike isNamedBy() this does not depend on
     * the planner having emitted the same zone first, so an explicit
     * "10:00 UTC" wins even when the planner guessed otherwise.
     */
    private static function namedIn(string $messageText): ?string
    {
        if ('' === trim($messageText)) {
            return null;
        }
        if (1 === preg_match('/\b(UTC|GMT)\b(?![+-]\d)/i', $messageText)) {
            return 'UTC';
        }
        if (1 !== preg_match_all('#\b([A-Za-z][A-Za-z0-9_.+\-]*(?:/[A-Za-z0-9_.+\-]+)+)\b#', $messageText, $matches)) {
            return null;
        }
        foreach ($matches[1] as $candidate) {
            // Canonical lookup doubles as validation: lookalikes ("and/or",
            // "Q3/2026") are not zones, and any casing ("europe/berlin")
            // resolves to the spelled name ("Europe/Berlin").
            $canonical = self::canonical($candidate);
            if (null !== $canonical) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Canonical IANA spelling for a zone name in any casing, or null when it
     * names no zone at all. Cached per request; the list is ~400 entries.
     */
    private static function canonical(string $candidate): ?string
    {
        static $byLower = null;
        if (null === $byLower) {
            $byLower = [];
            foreach (\DateTimeZone::listIdentifiers() as $id) {
                $byLower[strtolower($id)] = $id;
            }
        }

        return $byLower[strtolower($candidate)] ?? null;
    }

    /**
     * Did the user name this planner zone in their own message (as opposed to
     * the planner inferring or defaulting it)? Matches the IANA name itself,
     * a bare UTC/GMT token for UTC, or the city the zone is named after.
     */
    private static function isNamedBy(string $tzName, string $messageText): bool
    {
        if ('' === trim($messageText)) {
            return false;
        }
        if (str_contains(mb_strtolower($messageText), mb_strtolower($tzName))) {
            return true;
        }
        if ('UTC' === strtoupper($tzName)) {
            // Bare token only, word-boundary guarded: "production" must not
            // corroborate UTC, and "UTC+2" names an offset, not UTC itself.
            return 1 === preg_match('/\b(UTC|GMT)\b(?![+-]\d)/i', $messageText);
        }

        $slash = strrpos($tzName, '/');
        $city = str_replace('_', ' ', false === $slash ? $tzName : substr($tzName, $slash + 1));

        return '' !== $city && 1 === preg_match('/\b'.preg_quote($city, '/').'\b/iu', $messageText);
    }
}
