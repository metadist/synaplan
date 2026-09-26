<?php

declare(strict_types=1);

namespace App\AI\Messages;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ApiKeyScope;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who an API-session chat belongs to, and the title stamp shown in history.
 *
 * POST /v1/messages used to label every caller "Claude Code". A paired
 * Synaplan Desktop key (or a client that names itself in the User-Agent)
 * is a different product and must not share that title.
 */
final class ApiSessionClient
{
    public const CLAUDE_CODE = 'claude-code';
    public const DESKTOP = 'synaplan-desktop';

    public static function fromRequest(Request $request): string
    {
        $key = $request->attributes->get('api_key');
        if ($key instanceof ApiKey && ApiKeyScope::isPairedDesktop($key->getScopes())) {
            return self::DESKTOP;
        }

        $agent = strtolower((string) $request->headers->get('User-Agent', ''));
        if (str_contains($agent, 'synaplan-desktop')) {
            return self::DESKTOP;
        }

        return self::CLAUDE_CODE;
    }

    public static function label(string $client): string
    {
        return match ($client) {
            self::CLAUDE_CODE => 'Claude Code',
            self::DESKTOP => 'Synaplan Desktop',
            'openai-api' => 'API client (OpenAI-compatible)',
            default => 'API client',
        };
    }

    /**
     * Account timezone when it is a real IANA name, otherwise UTC.
     * The server clock is not the person's local time.
     */
    public static function localStamp(User $user, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $name = $user->getUserDetails()['timezone'] ?? '';
        $tz = new \DateTimeZone('UTC');
        if (\is_string($name) && '' !== trim($name)) {
            try {
                $tz = new \DateTimeZone(trim($name));
            } catch (\Throwable) {
                $tz = new \DateTimeZone('UTC');
            }
        }

        return $now->setTimezone($tz)->format('Y-m-d H:i');
    }

    /**
     * Keep the original stamp when an in-progress desktop session was titled
     * as Claude Code before the client id was distinguished.
     */
    public static function retitleDesktop(string $title): ?string
    {
        if (1 !== preg_match('/^Claude Code · (\d{4}-\d{2}-\d{2} \d{2}:\d{2})$/', trim($title), $match)) {
            return null;
        }

        return self::label(self::DESKTOP).' · '.$match[1];
    }
}
