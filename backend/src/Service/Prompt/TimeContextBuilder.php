<?php

declare(strict_types=1);

namespace App\Service\Prompt;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Builds the "current date and time" block appended to chat system prompts.
 *
 * Why this exists
 * ---------------
 * Models have no clock. The chat pipeline already ships the message's
 * creation timestamp inside the user turn (BDATETIME / BUNIXTIMES), but that
 * value is the raw `YmdHis` server stamp — no weekday, no timezone label, and
 * on a UTC server it silently differs from the user's wall clock (a 21:05
 * message in Germany arrives stamped 19:05). This builder upgrades that crude
 * signal into one explicit, unambiguous line so the model can resolve "today",
 * "tomorrow" or "next Monday" without guessing.
 *
 * It deliberately does NOT replace the BDATETIME mechanism — that stays as-is
 * for the legacy prompt-template substitution; this only adds a clean,
 * human-readable system-prompt line on top.
 *
 * Timezone resolution (best signal first)
 * ---------------------------------------
 *   1. The user's stored IANA timezone (profile setting) — authoritative.
 *   2. The Cloudflare edge country, but ONLY when every timezone in that
 *      country currently shares one UTC offset, so the wall-clock time is
 *      unambiguous (DE, FR, GB, NL, …). Multi-offset countries (US, RU, ES,
 *      BR, AU, …) are skipped — we cannot know which zone the user is in.
 *   3. The server timezone, clearly labelled as such, with a caveat that it
 *      may differ from the user's local time.
 *
 * The clock is injected (Symfony's auto-registered {@see ClockInterface}, with
 * a {@see NativeClock} fallback) so the output is deterministic under test via
 * {@see \Symfony\Component\Clock\MockClock}.
 */
final readonly class TimeContextBuilder
{
    /**
     * Cloudflare CF-IPCountry sentinels that are not real countries
     * (XX = unknown, T1 = Tor exit). Mirrors {@see \App\Service\Message\Handler\ChatHandler}.
     */
    private const COUNTRY_SENTINELS = ['XX', 'T1'];

    public function __construct(
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @param string|null $userTimezone IANA tz from the user's profile (e.g. "Europe/Berlin"); null/invalid is ignored
     * @param string|null $countryCode  ISO 3166-1 alpha-2 from CF-IPCountry (e.g. "DE"); sentinels XX/T1 are ignored
     */
    public function build(?string $userTimezone = null, ?string $countryCode = null): string
    {
        $now = $this->clock->now();

        [$zone, $source] = $this->resolveTimezone($userTimezone, $countryCode, $now);

        $local = $now->setTimezone($zone);
        $human = $local->format('l, j F Y, H:i');        // Wednesday, 17 June 2026, 21:05
        $iso = $local->format(\DateTimeInterface::ATOM);  // 2026-06-17T21:05:00+02:00
        $offset = $local->format('P');                    // +02:00

        $lines = ["\n\n## Current date and time"];

        if ('profile' === $source) {
            $lines[] = sprintf('- Current local time for the user: %s (%s, UTC%s).', $human, $zone->getName(), $offset);
            $lines[] = sprintf('- ISO 8601: %s.', $iso);
        } elseif ('country' === $source) {
            // Offset only — we know the country, not the exact city, so we do
            // not assert a specific IANA zone name we merely guessed.
            $lines[] = sprintf('- Current local time (inferred from approximate country %s): %s (UTC%s).', strtoupper((string) $countryCode), $human, $offset);
            $lines[] = sprintf('- ISO 8601: %s.', $iso);
            $lines[] = '- This is inferred from network geolocation and may be wrong; if the exact local time matters, ask the user to confirm their timezone.';
        } else { // 'server'
            $lines[] = sprintf('- Current server time: %s (%s, UTC%s).', $human, $zone->getName(), $offset);
            $lines[] = sprintf('- ISO 8601: %s.', $iso);
            $lines[] = "- The user's timezone is unknown, so this is the server clock and may differ from their local time. If the exact local time matters, ask the user to confirm their timezone.";
        }

        $lines[] = 'Use this as "now" when resolving relative dates and times (e.g. "today", "tomorrow", "in 2 hours", "next Monday"). Do not restate this block unless the user asks about the current date or time.';

        return implode("\n", $lines);
    }

    /**
     * @return array{0: \DateTimeZone, 1: 'profile'|'country'|'server'}
     */
    private function resolveTimezone(?string $userTimezone, ?string $countryCode, \DateTimeImmutable $now): array
    {
        // 1. Explicit user profile timezone wins.
        if (is_string($userTimezone) && '' !== trim($userTimezone)) {
            try {
                return [new \DateTimeZone(trim($userTimezone)), 'profile'];
            } catch (\Exception) {
                // Invalid stored value — fall through to country / server.
            }
        }

        // 2. Country, only when its zones currently agree on a single offset.
        $countryZone = $this->resolveUnambiguousCountryZone($countryCode, $now);
        if (null !== $countryZone) {
            return [$countryZone, 'country'];
        }

        // 3. Server timezone fallback.
        return [new \DateTimeZone(date_default_timezone_get()), 'server'];
    }

    /**
     * Resolve a country to a timezone ONLY when every IANA zone registered for
     * that country currently shares one UTC offset. That makes the wall-clock
     * time unambiguous (e.g. DE → Europe/Berlin + Europe/Busingen, both +02:00)
     * while correctly bailing out for spread-out countries (US, RU, ES, …).
     */
    private function resolveUnambiguousCountryZone(?string $countryCode, \DateTimeImmutable $now): ?\DateTimeZone
    {
        if (!is_string($countryCode)) {
            return null;
        }

        $cc = strtoupper(trim($countryCode));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc) || in_array($cc, self::COUNTRY_SENTINELS, true)) {
            return null;
        }

        $identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $cc);
        if ([] === $identifiers) {
            return null;
        }

        $offsets = [];
        $representative = null;
        foreach ($identifiers as $id) {
            try {
                $zone = new \DateTimeZone($id);
            } catch (\Exception) {
                continue;
            }
            $representative ??= $zone;
            $offsets[$zone->getOffset($now)] = true;
        }

        // Ambiguous (the country spans multiple current offsets) or no valid
        // identifier — we cannot state a single local time confidently.
        if (null === $representative || 1 !== count($offsets)) {
            return null;
        }

        return $representative;
    }
}
