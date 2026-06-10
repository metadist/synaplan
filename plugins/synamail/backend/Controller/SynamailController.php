<?php

declare(strict_types=1);

namespace Plugin\Synamail\Controller;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Synamail plugin — rolling AI profiles of mailing partners.
 *
 * One profile per contact (keyed by lower-cased email). Each call to
 * `POST /profiles/{email}/update` rolls one email into the stored profile:
 * the existing summary + the new email are sent to the AI, which returns a
 * merged summary / tone / facts / open loops. Deterministic fields (email
 * count, first/last seen, org-from-domain) are computed in code, never by
 * the model.
 *
 * Storage is the generic `plugin_data` table (dataType `synamail_profile`),
 * so the plugin needs no schema changes and a profile is deleted with one
 * row — important for the privacy/GDPR story of the Outlook add-in.
 */
#[Route('/api/v1/user/{userId}/plugins/synamail', name: 'api_plugin_synamail_')]
#[OA\Tag(name: 'Synamail Plugin')]
class SynamailController extends AbstractController
{
    private const PLUGIN_NAME = 'synamail';
    private const CONFIG_GROUP = 'P_synamail';
    private const DATA_TYPE_PROFILE = 'synamail_profile';

    /** Hard cap on the email body text fed into one profile update. */
    private const MAX_BODY_CHARS = 8000;

    /** Caps applied to the AI output so a profile stays a compact snapshot. */
    private const MAX_FACTS = 10;
    private const MAX_OPEN_LOOPS = 8;

    public function __construct(
        private PluginDataService $pluginData,
        private ConfigRepository $configRepository,
        private ModelConfigService $modelConfigService,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    // =========================================================================
    // Profiles
    // =========================================================================

    #[Route('/profiles', name: 'profiles_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles',
        summary: 'List all contact profiles',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin']
    )]
    #[OA\Response(response: 200, description: 'All stored profiles, newest first')]
    public function listProfiles(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $profiles = array_values($this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROFILE));
        usort($profiles, static fn (array $a, array $b): int => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));

        return $this->json(['success' => true, 'profiles' => $profiles]);
    }

    #[Route('/profiles/{email}', name: 'profile_get', requirements: ['email' => '[^/]+'], methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}',
        summary: 'Fetch the rolling profile of one contact',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin']
    )]
    #[OA\Response(response: 200, description: 'The profile, or profile: null when none exists yet')]
    public function getProfile(int $userId, string $email, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $email = $this->normalizeEmail($email);
        if (null === $email) {
            return $this->json(['success' => false, 'error' => 'Invalid email'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'profile' => $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROFILE, $this->profileKey($email)),
        ]);
    }

    #[Route('/profiles/{email}/update', name: 'profile_update', requirements: ['email' => '[^/]+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}/update',
        summary: 'Roll one email into the contact profile',
        description: 'Sends the existing profile plus the new email to the AI and stores the merged result. Deterministic fields (counts, dates, org) are computed server-side.',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['body'],
            properties: [
                new OA\Property(property: 'subject', type: 'string', example: 'Re: demo follow-up'),
                new OA\Property(property: 'body', type: 'string', example: 'Hi, thanks for the call yesterday...'),
                new OA\Property(property: 'date', type: 'string', example: '2026-06-10T14:32:00Z', description: 'ISO 8601 date of the email'),
                new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound'], example: 'inbound', description: 'inbound = the contact wrote to me, outbound = I wrote to the contact'),
                new OA\Property(property: 'name', type: 'string', example: 'Alice Example', description: 'Display name of the contact, if known'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'The updated profile')]
    public function updateProfile(int $userId, string $email, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $email = $this->normalizeEmail($email);
        if (null === $email) {
            return $this->json(['success' => false, 'error' => 'Invalid email'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $body = trim((string) ($payload['body'] ?? ''));
        if ('' === $body) {
            return $this->json(['success' => false, 'error' => 'Field "body" is required'], Response::HTTP_BAD_REQUEST);
        }

        $subject = trim((string) ($payload['subject'] ?? ''));
        $direction = 'outbound' === ($payload['direction'] ?? 'inbound') ? 'outbound' : 'inbound';
        $date = $this->normalizeDate((string) ($payload['date'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROFILE, $this->profileKey($email));
        $profile = $existing ?? $this->emptyProfile($email);

        // --- AI pass: merge the new email into the rolling narrative. -------
        $prompt = $this->buildProfileUpdatePrompt(
            $this->narrativeSlice($profile),
            $this->buildEmailBlock($email, $name, $subject, $body, $date, $direction),
            $this->getConfigValue($userId, 'profile_language', 'auto'),
            $this->getMaxSummaryWords($userId),
        );

        $messages = [
            ['role' => 'system', 'content' => 'You maintain rolling profiles of email correspondents. Return only valid JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $result = $this->aiFacade->chat($messages, $userId, $this->resolveAiModelOptions($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Synamail profile AI call failed', ['userId' => $userId, 'error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => 'AI request failed'], Response::HTTP_BAD_GATEWAY);
        }

        $narrative = $this->parseNarrative((string) ($result['content'] ?? ''));
        if (null === $narrative) {
            $this->logger->warning('Synamail profile AI returned unparseable JSON', ['userId' => $userId]);

            return $this->json(['success' => false, 'error' => 'AI returned an unusable response'], Response::HTTP_BAD_GATEWAY);
        }

        // --- Deterministic fields: computed in code, never by the model. ----
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);
        $profile['summary'] = $narrative['summary'];
        $profile['tone'] = $narrative['tone'];
        $profile['facts'] = array_slice($narrative['facts'], 0, self::MAX_FACTS);
        $profile['openLoops'] = array_slice($narrative['openLoops'], 0, self::MAX_OPEN_LOOPS);
        $profile['emailCount'] = (int) ($profile['emailCount'] ?? 0) + 1;
        $profile['firstSeen'] = $profile['firstSeen'] ?? ($date ?? $now);
        if ('inbound' === $direction) {
            $profile['lastInbound'] = $date ?? $now;
        } else {
            $profile['lastOutbound'] = $date ?? $now;
        }
        if ('' !== $name) {
            $profile['name'] = $name;
        }
        $profile['org'] = $this->orgFromEmail($email);
        $profile['updatedAt'] = $now;

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROFILE, $this->profileKey($email), $profile);

        return $this->json(['success' => true, 'profile' => $profile]);
    }

    #[Route('/profiles/{email}', name: 'profile_delete', requirements: ['email' => '[^/]+'], methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/synamail/profiles/{email}',
        summary: 'Delete a contact profile entirely',
        security: [['ApiKey' => []]],
        tags: ['Synamail Plugin']
    )]
    #[OA\Response(response: 200, description: 'Deletion result')]
    public function deleteProfile(int $userId, string $email, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $email = $this->normalizeEmail($email);
        if (null === $email) {
            return $this->json(['success' => false, 'error' => 'Invalid email'], Response::HTTP_BAD_REQUEST);
        }

        $deleted = $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_PROFILE, $this->profileKey($email));

        return $this->json(['success' => true, 'deleted' => $deleted]);
    }

    // =========================================================================
    // Prompt
    // =========================================================================

    /**
     * The rolling-summary prompt. The existing narrative (summary/tone/facts/
     * open loops) and ONE new email go in; the merged narrative comes out.
     *
     * Design notes:
     *  - "Merge, don't restart": the model must preserve still-valid knowledge
     *    and only revise what the new email changes.
     *  - Open loops are the headline behaviour ("you promised a demo") — the
     *    model both adds new commitments and resolves ones the new email
     *    fulfils.
     *  - Dates/counters are NOT requested from the model; code owns those.
     */
    private function buildProfileUpdatePrompt(array $narrative, string $emailBlock, string $language, int $maxSummaryWords): string
    {
        $existingJson = json_encode($narrative, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $languageRule = 'auto' === $language
            ? 'Write in the language the correspondence itself uses (keep the language of the existing profile if one exists).'
            : sprintf('Write in language code "%s".', $language);

        return <<<PROMPT
        You maintain a ROLLING PROFILE of one email correspondent. A profile is a compact,
        evolving picture of who this person is and where the relationship stands. You are
        given the existing profile and exactly ONE new email; produce the updated profile.

        Existing profile (may be empty on first contact):
        {$existingJson}

        New email:
        {$emailBlock}

        Update rules — MERGE, never restart:
        - "summary": one rolling narrative, maximum {$maxSummaryWords} words. Who the person is,
          what the relationship is about, how it is developing, and what happened most recently.
          Keep what is still true from the existing summary; weave in what the new email adds or
          changes. Recent events may displace older detail, but never drop who the person is.
        - "tone": 2-6 words describing the current tone of the relationship
          (e.g. "friendly but distanced", "formal, urgent"). Update it only when the new
          email actually shifts the tone.
        - "facts": short, stable facts about the person worth remembering (role, company,
          timezone, preferences, recurring topics). Keep existing facts that still hold,
          add new ones, correct contradicted ones. Each fact is one short sentence.
        - "openLoops": unresolved commitments and questions, each one short sentence
          prefixed with who owes it: "me:" (I owe the contact) or "them:" (the contact owes me).
          Add commitments the new email creates. REMOVE loops the new email resolves.
          Direction matters: "inbound" means the contact wrote to me; "outbound" means I wrote
          to the contact.
        - Never invent dates, counts, or facts that appear in neither the existing profile
          nor the new email. Do not include dates you are not given.
        - {$languageRule}

        Return ONLY a valid JSON object, no markdown fences, no commentary:
        {"summary": "...", "tone": "...", "facts": ["..."], "openLoops": ["me: ...", "them: ..."]}
        PROMPT;
    }

    private function buildEmailBlock(string $email, string $name, string $subject, string $body, ?string $date, string $direction): string
    {
        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS)."\n[... truncated ...]";
        }

        $who = '' !== $name ? sprintf('%s <%s>', $name, $email) : $email;
        $lines = [
            'Contact: '.$who,
            'Direction: '.$direction.(('inbound' === $direction) ? ' (the contact wrote to me)' : ' (I wrote to the contact)'),
        ];
        if (null !== $date) {
            $lines[] = 'Date: '.$date;
        }
        if ('' !== $subject) {
            $lines[] = 'Subject: '.$subject;
        }
        $lines[] = '';
        $lines[] = $body;

        return implode("\n", $lines);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if (null === $user) {
            return false;
        }

        if ($user->getId() !== $userId) {
            return false;
        }

        return '1' === $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'enabled');
    }

    private function getConfigValue(int $userId, string $key, string $default): string
    {
        return $this->configRepository->getValue($userId, self::CONFIG_GROUP, $key) ?? $default;
    }

    private function getMaxSummaryWords(int $userId): int
    {
        $value = (int) $this->getConfigValue($userId, 'max_summary_words', '150');

        return ($value >= 50 && $value <= 500) ? $value : 150;
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = mb_strtolower(trim(rawurldecode($email)));

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Storage key for one contact's profile. `plugin_data` sanitizes keys to
     * `[a-z0-9_]` on write (PluginData::sanitizeKey), which would mangle a raw
     * email ('alice@example.com' → 'aliceexamplecom') and silently break
     * read-after-write. A hex hash survives sanitization untouched and cannot
     * collide; the full email lives inside the profile JSON itself.
     */
    private function profileKey(string $email): string
    {
        return 'p_'.sha1($email);
    }

    private function normalizeDate(string $date): ?string
    {
        if ('' === trim($date)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($date))->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function orgFromEmail(string $email): ?string
    {
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        $freemail = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'yahoo.com', 'icloud.com', 'gmx.de', 'gmx.net', 'web.de', 't-online.de', 'proton.me', 'protonmail.com'];

        return in_array($domain, $freemail, true) ? null : $domain;
    }

    /** @return array{email: string, summary: string, tone: ?string, facts: list<string>, openLoops: list<string>, emailCount: int, firstSeen: ?string} */
    private function emptyProfile(string $email): array
    {
        return [
            'email' => $email,
            'summary' => '',
            'tone' => null,
            'facts' => [],
            'openLoops' => [],
            'emailCount' => 0,
            'firstSeen' => null,
        ];
    }

    /** The slice of the profile the AI sees and rewrites (no counters/dates). */
    private function narrativeSlice(array $profile): array
    {
        return [
            'summary' => (string) ($profile['summary'] ?? ''),
            'tone' => $profile['tone'] ?? null,
            'facts' => array_values((array) ($profile['facts'] ?? [])),
            'openLoops' => array_values((array) ($profile['openLoops'] ?? [])),
        ];
    }

    /**
     * Parse the model's JSON reply, tolerating markdown fences and prose.
     *
     * @return array{summary: string, tone: ?string, facts: list<string>, openLoops: list<string>}|null
     */
    private function parseNarrative(string $text): ?array
    {
        $cleaned = trim((string) preg_replace('/```(?:json)?/i', '', $text));
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if (false === $start || false === $end || $end <= $start) {
            return null;
        }

        $parsed = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (!is_array($parsed)) {
            return null;
        }

        $summary = trim((string) ($parsed['summary'] ?? ''));
        if ('' === $summary) {
            return null;
        }

        $toStringList = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $item) {
                if (is_string($item) && '' !== trim($item)) {
                    $out[] = trim($item);
                }
            }

            return $out;
        };

        $tone = isset($parsed['tone']) && is_string($parsed['tone']) && '' !== trim($parsed['tone'])
            ? trim($parsed['tone'])
            : null;

        return [
            'summary' => $summary,
            'tone' => $tone,
            'facts' => $toStringList($parsed['facts'] ?? null),
            'openLoops' => $toStringList($parsed['openLoops'] ?? null),
        ];
    }

    /** @return array<string, string> */
    private function resolveAiModelOptions(int $userId): array
    {
        foreach ([$userId, 0] as $uid) {
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $uid);
            if ($modelId) {
                return [
                    'model' => $this->modelConfigService->getModelName($modelId),
                    'provider' => $this->modelConfigService->getProviderForModel($modelId),
                ];
            }
        }

        return [];
    }
}
