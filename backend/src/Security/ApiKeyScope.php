<?php

declare(strict_types=1);

namespace App\Security;

/**
 * API key scope vocabulary and the path → required-scope enforcement map.
 *
 * Historically {@see \App\Entity\ApiKey::hasScope()} was never called: any
 * `sk_*` key had full account access. That is the CORE-3 / July-local-agent
 * blocker this class closes. It is a *security fix*, so it must not break the
 * integrations that already rely on unscoped keys.
 *
 * Grandfather rule (do NOT narrow existing keys):
 *   - an EMPTY scope list means full access (legacy);
 *   - a list that only contains the legacy webhook scopes
 *     (`webhooks:email` / `webhooks:whatsapp` / `webhooks:*`) means full access;
 *   - an explicit `*` means full access.
 *
 * A key is *restricted* only when it opts into a non-empty, non-legacy list
 * without `*`. Two integrations mint exactly such restricted keys: desktop
 * pairing ({@see pairingScopes()}) and the Outlook add-in connect flow
 * ({@see addinScopes()}), so a stolen laptop or mailbox is a revoke, not an
 * account takeover. Both vocabularies are mapped in
 * {@see requiredScopesForPath()}.
 *
 * This class is pure logic (no I/O), so it is unit-testable in isolation and is
 * the single source of truth shared by the entity and the enforcement
 * subscriber ({@see ApiKeyScopeSubscriber}).
 */
final class ApiKeyScope
{
    /** Explicit full access. */
    public const WILDCARD = '*';

    /** `/v1/messages`, `/v1/models`, `/v1/messages/count_tokens`. */
    public const DESKTOP_MESSAGES = 'desktop:messages';

    /** `/mcp`. */
    public const DESKTOP_MCP = 'desktop:mcp';

    /** `/api/v1/files*` — upload/list/download the owner already may. */
    public const DESKTOP_FILES = 'desktop:files';

    /** Check-in / report (declared in Sprint A1, first enforced in Sprint A3). */
    public const DESKTOP_JOBS = 'desktop:jobs';

    /** Umbrella over every `desktop:` scope. */
    public const DESKTOP_ALL = 'desktop:*';

    /**
     * Add-in area scopes, minted by the Outlook add-in connect flow
     * (`AddinConnectView.vue`) since long before enforcement existed. They are
     * a live contract with every already-issued Synamail key, so the strings
     * are frozen — see the prefix map in {@see requiredScopesForPath()} for
     * what each one grants.
     */

    /** `/api/v1/messages*`, `/api/v1/tts*`, `/api/v1/config/models*`, plugin routes — the AI-action surface. */
    public const ADDIN_MESSAGES = 'messages:*';

    /** `/api/v1/chats*` — chat persistence (create chat, load history). */
    public const ADDIN_CHATS = 'chats:*';

    /** `/api/v1/files*` — upload/list/download the owner already may. */
    public const ADDIN_FILES = 'files:*';

    /** `/api/v1/rag*` — semantic search over the owner's documents. */
    public const ADDIN_RAG = 'rag:*';

    /** `GET /api/v1/groups*` — list groups the key's owner belongs to. */
    public const IAM_READ = 'iam:read';

    /** `/api/v1/admin/groups*` — create/rename/delete groups and memberships. Implies iam:read. */
    public const IAM_MANAGE = 'iam:manage';

    /**
     * Paths any authenticated key may reach regardless of scopes: identity
     * introspection of the key's own account ("who am I"), needed by every
     * integration for its ping/health check. Read-only and owner-scoped.
     *
     * @var list<string>
     */
    private const SELF_SERVICE_PATHS = ['/api/v1/auth/me'];

    /**
     * Legacy webhook scopes. A key whose list contains ONLY these keeps full
     * access until the later CORE-3 migration narrows them explicitly.
     *
     * @var list<string>
     */
    public const LEGACY_WEBHOOK_SCOPES = ['webhooks:email', 'webhooks:whatsapp', 'webhooks:*'];

    private function __construct()
    {
    }

    /**
     * The exact scope set minted for a paired computer. Deliberately narrow:
     * a desktop key can chat, use MCP, move its own files, and run the job
     * loop — and nothing else (no admin, users, widgets, or webhooks).
     *
     * @return list<string>
     */
    public static function pairingScopes(): array
    {
        return [
            self::DESKTOP_MESSAGES,
            self::DESKTOP_MCP,
            self::DESKTOP_FILES,
            self::DESKTOP_JOBS,
        ];
    }

    /**
     * The scope set the Outlook add-in connect flow mints
     * (`frontend/src/views/AddinConnectView.vue`). Frozen: already-issued
     * Synamail keys carry exactly this list.
     *
     * @return list<string>
     */
    public static function addinScopes(): array
    {
        return [
            self::ADDIN_MESSAGES,
            self::ADDIN_CHATS,
            self::ADDIN_FILES,
            self::ADDIN_RAG,
        ];
    }

    /**
     * A key is restricted iff its scope list is non-empty, is not a
     * legacy-webhook-only list, and does not contain `*`.
     *
     * @param list<string>|array<int|string, mixed> $scopes
     */
    public static function isRestricted(array $scopes): bool
    {
        $normalized = self::normalize($scopes);

        if ([] === $normalized) {
            return false; // legacy empty = full access
        }

        if (\in_array(self::WILDCARD, $normalized, true)) {
            return false; // explicit full access
        }

        // A list that adds nothing beyond the legacy webhook scopes stays full
        // access (CORE-3 grandfather). Any other opt-in scope restricts the key.
        $beyondLegacy = array_diff($normalized, self::LEGACY_WEBHOOK_SCOPES);

        return [] !== $beyondLegacy;
    }

    /**
     * Whether a restricted key with $scopes may reach $path.
     *
     * Only meaningful for restricted keys — callers gate with
     * {@see isRestricted()} first (an unrestricted key is always allowed).
     *
     * Prefix map (v1):
     *   /v1/                     → desktop:messages
     *   /mcp                     → desktop:mcp
     *   /api/v1/desktop/         → desktop:jobs
     *   /api/v1/files            → desktop:files OR files:* (a paired computer
     *                              uploads its result artifact through the
     *                              existing files API before reporting a
     *                              fileId — sprint A3 §2.4; the add-in uploads
     *                              mail attachments the same way)
     *   /api/v1/messages         → messages:*
     *   /api/v1/tts              → messages:* (read-aloud of an AI answer)
     *   /api/v1/config/models    → messages:* (read-only model info)
     *   /api/v1/user/{id}/plugins→ messages:* (plugin AI features, e.g.
     *                              Synamail contact profiling)
     *   /api/v1/chats            → chats:*
     *   /api/v1/rag              → rag:*
     *   /api/v1/groups           → iam:read
     *   /api/v1/admin/groups     → iam:manage (implies iam:read)
     *   /api/v1/auth/me          → any key (self-service identity, see
     *                              SELF_SERVICE_PATHS)
     *   everything else          → denied for a restricted key (a scoped key
     *                              must never administer the instance)
     *
     * @param list<string>|array<int|string, mixed> $scopes
     */
    public static function allows(array $scopes, string $path): bool
    {
        if (\in_array($path, self::SELF_SERVICE_PATHS, true)) {
            return true;
        }

        $normalized = self::normalize($scopes);

        if (\in_array(self::WILDCARD, $normalized, true)) {
            return true;
        }

        foreach (self::requiredScopesForPath($path) as $required) {
            if (self::grants($normalized, $required)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the request is a key revoking *itself* (the Synamail sign-out
     * flow). Always allowed for any valid key regardless of scopes: it is
     * security-positive — a leaked key can only destroy itself, never touch
     * the owner's other keys.
     */
    public static function isSelfRevoke(string $method, string $path, int $keyId): bool
    {
        return 'DELETE' === strtoupper($method) && sprintf('/api/v1/apikeys/%d', $keyId) === $path;
    }

    /**
     * The scopes that satisfy a path, in the enforcement prefix order. An empty
     * list means "no restricted key may reach this path" (deny).
     *
     * @return list<string>
     */
    public static function requiredScopesForPath(string $path): array
    {
        if ('/v1' === $path || str_starts_with($path, '/v1/')) {
            return [self::DESKTOP_MESSAGES];
        }

        if ('/mcp' === $path || str_starts_with($path, '/mcp')) {
            return [self::DESKTOP_MCP];
        }

        if (self::matchesPrefix($path, '/api/v1/desktop')) {
            return [self::DESKTOP_JOBS];
        }

        if (self::matchesPrefix($path, '/api/v1/files')) {
            return [self::DESKTOP_FILES, self::ADDIN_FILES];
        }

        if (self::matchesPrefix($path, '/api/v1/messages')
            || self::matchesPrefix($path, '/api/v1/tts')
            || self::matchesPrefix($path, '/api/v1/config/models')
            || 1 === preg_match('#^/api/v1/user/\d+/plugins(/|$)#', $path)
        ) {
            return [self::ADDIN_MESSAGES];
        }

        if (self::matchesPrefix($path, '/api/v1/chats')) {
            return [self::ADDIN_CHATS];
        }

        if (self::matchesPrefix($path, '/api/v1/rag')) {
            return [self::ADDIN_RAG];
        }

        if (self::matchesPrefix($path, '/api/v1/groups')) {
            return [self::IAM_READ];
        }

        if (self::matchesPrefix($path, '/api/v1/admin/groups')) {
            return [self::IAM_MANAGE];
        }

        return [];
    }

    /** `$prefix` itself, or anything below `$prefix/` — never `$prefix<other-chars>`. */
    private static function matchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Does $scopes grant $required, honouring the `desktop:*` umbrella?
     *
     * @param list<string> $scopes
     */
    private static function grants(array $scopes, string $required): bool
    {
        if (\in_array($required, $scopes, true)) {
            return true;
        }

        if (str_starts_with($required, 'desktop:') && \in_array(self::DESKTOP_ALL, $scopes, true)) {
            return true;
        }

        if (self::IAM_READ === $required && \in_array(self::IAM_MANAGE, $scopes, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $scopes
     *
     * @return list<string>
     */
    private static function normalize(array $scopes): array
    {
        $out = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope)) {
                continue;
            }
            $trimmed = trim($scope);
            if ('' !== $trimmed) {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }
}
