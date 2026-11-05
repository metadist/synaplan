<?php

declare(strict_types=1);

namespace Plugin\Synamail\Service;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use App\Service\PluginDataService;
use Psr\Log\LoggerInterface;

/**
 * Rolling profiles of mailing partners.
 *
 * One profile per contact. Each update sends the stored narrative and one
 * email to the chat model, which returns a merged summary, tone, facts and
 * open loops. Counts, dates and the organisation are computed here.
 *
 * Storage is plugin_data (type synamail_profile). The key is a hash because
 * plugin_data reduces keys to [a-z0-9_], which would mangle an email address
 * and break the next read. The address itself lives in the profile JSON.
 */
final readonly class ContactProfileService
{
    public const PLUGIN = 'synamail';
    public const CONFIG_GROUP = 'P_synamail';
    public const DATA_TYPE = 'synamail_profile';

    private const MAX_BODY_CHARS = 8000;
    private const MAX_FACTS = 10;
    private const MAX_OPEN_LOOPS = 8;

    /** @var list<string> */
    private const FREEMAIL = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'yahoo.com',
        'icloud.com',
        'gmx.de',
        'gmx.net',
        'web.de',
        't-online.de',
        'proton.me',
        'protonmail.com',
    ];

    public function __construct(
        private PluginDataService $pluginData,
        private ConfigRepository $config,
        private ModelConfigService $models,
        private AiFacade $ai,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(int $userId): bool
    {
        return '1' === $this->config->getValue($userId, self::CONFIG_GROUP, 'enabled');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        $profiles = array_values($this->pluginData->list($userId, self::PLUGIN, self::DATA_TYPE));
        usort(
            $profiles,
            static fn (array $a, array $b): int => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')),
        );

        return $profiles;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, string $rawEmail): ?array
    {
        $email = $this->requireEmail($rawEmail);

        return $this->pluginData->get($userId, self::PLUGIN, self::DATA_TYPE, $this->profileKey($email));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function update(int $userId, string $rawEmail, array $payload): array
    {
        $email = $this->requireEmail($rawEmail);
        $turn = $this->turnFromPayload($payload);
        $existing = $this->pluginData->get($userId, self::PLUGIN, self::DATA_TYPE, $this->profileKey($email))
            ?? $this->emptyProfile($email);
        $narrative = $this->mergeNarrative($userId, $existing, $email, $turn);
        $profile = $this->withDeterministicFields($existing, $narrative, $email, $turn);

        $this->pluginData->set($userId, self::PLUGIN, self::DATA_TYPE, $this->profileKey($email), $profile);

        return $profile;
    }

    public function delete(int $userId, string $rawEmail): bool
    {
        $email = $this->requireEmail($rawEmail);

        return $this->pluginData->delete($userId, self::PLUGIN, self::DATA_TYPE, $this->profileKey($email));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{subject: string, body: string, direction: string, date: string|null, name: string}
     */
    private function turnFromPayload(array $payload): array
    {
        $body = trim((string) ($payload['body'] ?? ''));
        if ('' === $body) {
            throw new ProfileException('Field "body" is required', 400);
        }

        return [
            'subject' => trim((string) ($payload['subject'] ?? '')),
            'body' => $body,
            'direction' => 'outbound' === ($payload['direction'] ?? 'inbound') ? 'outbound' : 'inbound',
            'date' => $this->normalizeDate((string) ($payload['date'] ?? '')),
            'name' => trim((string) ($payload['name'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed>                                                                     $existing
     * @param array{subject: string, body: string, direction: string, date: string|null, name: string} $turn
     *
     * @return array{summary: string, tone: string|null, facts: list<string>, openLoops: list<string>}
     */
    private function mergeNarrative(int $userId, array $existing, string $email, array $turn): array
    {
        $prompt = $this->buildPrompt(
            $this->narrativeSlice($existing),
            $this->emailBlock($email, $turn),
            $this->configValue($userId, 'profile_language', 'auto'),
            $this->maxSummaryWords($userId),
        );
        $messages = [
            ['role' => 'system', 'content' => 'You maintain rolling profiles of email correspondents. Return only valid JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ];

        try {
            $result = $this->ai->chat($messages, $userId, $this->modelOptions($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Synamail profile merge failed', ['userId' => $userId, 'error' => $e->getMessage()]);

            throw new ProfileException('AI request failed', 502);
        }

        $content = $result['content'] ?? '';
        $narrative = $this->parseNarrative(is_string($content) ? $content : '');
        if (null === $narrative) {
            $this->logger->warning('Synamail profile merge returned unparseable JSON', ['userId' => $userId]);

            throw new ProfileException('AI returned an unusable response', 502);
        }

        return $narrative;
    }

    /**
     * @param array<string, mixed>                                                                     $existing
     * @param array{summary: string, tone: string|null, facts: list<string>, openLoops: list<string>}  $narrative
     * @param array{subject: string, body: string, direction: string, date: string|null, name: string} $turn
     *
     * @return array<string, mixed>
     */
    private function withDeterministicFields(array $existing, array $narrative, string $email, array $turn): array
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $seenAt = $turn['date'] ?? $now;
        $profile = $existing;
        $profile['email'] = $email;
        $profile['summary'] = $narrative['summary'];
        $profile['tone'] = $narrative['tone'];
        $profile['facts'] = array_slice($narrative['facts'], 0, self::MAX_FACTS);
        $profile['openLoops'] = array_slice($narrative['openLoops'], 0, self::MAX_OPEN_LOOPS);
        $profile['emailCount'] = (int) ($existing['emailCount'] ?? 0) + 1;
        $profile['firstSeen'] = $existing['firstSeen'] ?? $seenAt;
        if ('inbound' === $turn['direction']) {
            $profile['lastInbound'] = $seenAt;
        } else {
            $profile['lastOutbound'] = $seenAt;
        }
        if ('' !== $turn['name']) {
            $profile['name'] = $turn['name'];
        }
        $profile['org'] = $this->orgFromEmail($email);
        $profile['updatedAt'] = $now;

        return $profile;
    }

    /**
     * @param array{summary: string, tone: string|null, facts: list<string>, openLoops: list<string>} $narrative
     */
    private function buildPrompt(array $narrative, string $emailBlock, string $language, int $maxSummaryWords): string
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

    /**
     * @param array{subject: string, body: string, direction: string, date: string|null, name: string} $turn
     */
    private function emailBlock(string $email, array $turn): string
    {
        $body = $turn['body'];
        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS)."\n[... truncated ...]";
        }

        $who = '' !== $turn['name'] ? sprintf('%s <%s>', $turn['name'], $email) : $email;
        $direction = $turn['direction'];
        $lines = [
            'Contact: '.$who,
            'Direction: '.$direction.(('inbound' === $direction) ? ' (the contact wrote to me)' : ' (I wrote to the contact)'),
        ];
        if (null !== $turn['date']) {
            $lines[] = 'Date: '.$turn['date'];
        }
        if ('' !== $turn['subject']) {
            $lines[] = 'Subject: '.$turn['subject'];
        }
        $lines[] = '';
        $lines[] = $body;

        return implode("\n", $lines);
    }

    /**
     * @return array{summary: string, tone: string|null, facts: list<string>, openLoops: list<string>}|null
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

        $tone = isset($parsed['tone']) && is_string($parsed['tone']) && '' !== trim($parsed['tone'])
            ? trim($parsed['tone'])
            : null;

        return [
            'summary' => $summary,
            'tone' => $tone,
            'facts' => $this->stringList($parsed['facts'] ?? null),
            'openLoops' => $this->stringList($parsed['openLoops'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
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
    }

    /** @return array<string, string> */
    private function modelOptions(int $userId): array
    {
        foreach ([$userId, 0] as $uid) {
            $modelId = $this->models->getDefaultModel('CHAT', $uid);
            if (null === $modelId || 0 === $modelId) {
                continue;
            }
            $name = $this->models->getModelName($modelId);
            $provider = $this->models->getProviderForModel($modelId);
            if (null === $name || '' === $name || null === $provider || '' === $provider) {
                continue;
            }

            return ['model' => $name, 'provider' => $provider];
        }

        return [];
    }

    private function configValue(int $userId, string $key, string $default): string
    {
        return $this->config->getValue($userId, self::CONFIG_GROUP, $key) ?? $default;
    }

    private function maxSummaryWords(int $userId): int
    {
        $value = (int) $this->configValue($userId, 'max_summary_words', '150');

        return ($value >= 50 && $value <= 500) ? $value : 150;
    }

    private function requireEmail(string $email): string
    {
        $email = mb_strtolower(trim(rawurldecode($email)));
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ProfileException('Invalid email', 400);
        }

        return $email;
    }

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
            return (new \DateTimeImmutable($date))->format(\DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function orgFromEmail(string $email): ?string
    {
        $domain = substr($email, (int) strrpos($email, '@') + 1);

        return in_array($domain, self::FREEMAIL, true) ? null : $domain;
    }

    /**
     * @return array{email: string, summary: string, tone: null, facts: list<string>, openLoops: list<string>, emailCount: int, firstSeen: null}
     */
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

    /**
     * @param array<string, mixed> $profile
     *
     * @return array{summary: string, tone: string|null, facts: list<string>, openLoops: list<string>}
     */
    private function narrativeSlice(array $profile): array
    {
        $tone = $profile['tone'] ?? null;

        return [
            'summary' => (string) ($profile['summary'] ?? ''),
            'tone' => is_string($tone) && '' !== $tone ? $tone : null,
            'facts' => $this->stringList($profile['facts'] ?? null),
            'openLoops' => $this->stringList($profile['openLoops'] ?? null),
        ];
    }
}
