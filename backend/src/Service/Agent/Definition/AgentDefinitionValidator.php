<?php

declare(strict_types=1);

namespace App\Service\Agent\Definition;

use App\Service\Agent\Exception\AgentDefinitionException;

/**
 * Deny-unknown-fields validator for `agent.v1`.
 *
 * Unknown keys at any level are rejected with the JSON path in the
 * message (`Unknown key "tools.foo" in agent.v1`). Missing optional
 * sections are filled from {@see AgentDefinition::defaults()}.
 */
final class AgentDefinitionValidator
{
    /**
     * The accepted key sets, public so the OpenAPI component
     * ({@see \App\DTO\AgentDefinitionV1}) can be checked against them.
     */
    public const ROOT_KEYS = [
        'schema',
        'models',
        'knowledge',
        'tools',
        'skills',
        'parameters',
        'behaviour',
        'triggers',
    ];

    public const MODEL_KEYS = ['chat', 'vision', 'vectorize'];

    public const KNOWLEDGE_KEYS = ['ownFolder', 'folders', 'includeUserFiles', 'ragLimit', 'ragMinScore'];

    public const TOOL_KEYS = ['internet', 'files', 'mcpServers', 'allow', 'deny'];

    public const SKILL_KEYS = ['allow', 'deny'];

    public const PARAMETER_KEYS = ['temperature', 'maxTokens', 'language', 'responseSchema'];

    public const BEHAVIOUR_KEYS = ['greeting', 'starterPrompts', 'memory'];

    public const TRIGGER_KEYS = ['events', 'schedules'];

    /** S5 known event keys — accepted here so later sprints do not reopen the schema. */
    public const EVENT_KEYS = [
        'id',
        'kind',
        'mailbox',
        'rule',
        'instruction',
        'widget',
        'widgetDefaults',
        'number',
        'enabled',
        'department',
    ];

    public const SCHEDULE_KEYS = [
        'id',
        'name',
        'every',
        'cron',
        'tz',
        'instruction',
        'allowUnattended',
        'enabled',
    ];

    public const EVENT_KINDS = ['mail', 'whatsapp', 'widget', 'api', 'mcp', 'desktop', 'webhook'];

    private const MODEL_KEY_PATTERN = '/^[a-z0-9._-]+:[a-z0-9._-]+(?::[a-z0-9._-]+)?$/i';

    private const FOLDER_PATTERN = '/^\d+:[A-Za-z0-9:_-]+$/';

    /**
     * @param array<mixed> $json
     */
    public function validate(array $json): AgentDefinition
    {
        if ([] !== $json && array_is_list($json)) {
            throw $this->fail('', 'agent.v1 must be an object');
        }

        $this->rejectUnknown(array_keys($json), self::ROOT_KEYS, '');

        if (isset($json['schema'])) {
            if (!is_string($json['schema']) || AgentDefinition::SCHEMA !== $json['schema']) {
                throw $this->fail('schema', 'schema must equal "agent.v1"');
            }
        }

        $defaults = AgentDefinition::defaults()->toArray();
        $merged = $defaults;

        if (isset($json['models'])) {
            $merged['models'] = $this->validateModels($json['models']);
        }
        if (isset($json['knowledge'])) {
            $merged['knowledge'] = $this->validateKnowledge($json['knowledge'], $defaults['knowledge']);
        }
        if (isset($json['tools'])) {
            $merged['tools'] = $this->validateTools($json['tools'], $defaults['tools']);
        }
        if (isset($json['skills'])) {
            $merged['skills'] = $this->validateSkills($json['skills'], $defaults['skills']);
        }
        if (isset($json['parameters'])) {
            $merged['parameters'] = $this->validateParameters($json['parameters'], $defaults['parameters']);
        }
        if (isset($json['behaviour'])) {
            $merged['behaviour'] = $this->validateBehaviour($json['behaviour'], $defaults['behaviour']);
        }
        if (isset($json['triggers'])) {
            $merged['triggers'] = $this->validateTriggers($json['triggers']);
        }

        $merged['schema'] = AgentDefinition::SCHEMA;

        return new AgentDefinition($merged);
    }

    /**
     * @return array<string, string|null>
     */
    private function validateModels(mixed $models): array
    {
        if (!is_array($models) || array_is_list($models)) {
            throw $this->fail('models', 'models must be an object');
        }
        $this->rejectUnknown(array_keys($models), self::MODEL_KEYS, 'models');

        $out = ['chat' => null, 'vision' => null, 'vectorize' => null];
        foreach (self::MODEL_KEYS as $capability) {
            if (!array_key_exists($capability, $models)) {
                continue;
            }
            $value = $models[$capability];
            if (null === $value) {
                $out[$capability] = null;
                continue;
            }
            if (!is_string($value) || 1 !== preg_match(self::MODEL_KEY_PATTERN, $value)) {
                throw $this->fail('models.'.$capability, 'models.'.$capability.' must be a catalog key "service:providerId:tag" or null');
            }
            $out[$capability] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function validateKnowledge(mixed $knowledge, array $defaults): array
    {
        if (!is_array($knowledge) || array_is_list($knowledge)) {
            throw $this->fail('knowledge', 'knowledge must be an object');
        }
        $this->rejectUnknown(array_keys($knowledge), self::KNOWLEDGE_KEYS, 'knowledge');

        $out = $defaults;
        if (array_key_exists('ownFolder', $knowledge)) {
            if (!is_bool($knowledge['ownFolder'])) {
                throw $this->fail('knowledge.ownFolder', 'knowledge.ownFolder must be a boolean');
            }
            $out['ownFolder'] = $knowledge['ownFolder'];
        }
        if (array_key_exists('folders', $knowledge)) {
            $out['folders'] = $this->stringList($knowledge['folders'], 'knowledge.folders', self::FOLDER_PATTERN);
        }
        if (array_key_exists('includeUserFiles', $knowledge)) {
            if (!is_bool($knowledge['includeUserFiles'])) {
                throw $this->fail('knowledge.includeUserFiles', 'knowledge.includeUserFiles must be a boolean');
            }
            $out['includeUserFiles'] = $knowledge['includeUserFiles'];
        }
        if (array_key_exists('ragLimit', $knowledge)) {
            if (!is_int($knowledge['ragLimit']) && !is_float($knowledge['ragLimit'])) {
                throw $this->fail('knowledge.ragLimit', 'knowledge.ragLimit must be a number');
            }
            $out['ragLimit'] = max(1, min(50, (int) $knowledge['ragLimit']));
        }
        if (array_key_exists('ragMinScore', $knowledge)) {
            if (!is_int($knowledge['ragMinScore']) && !is_float($knowledge['ragMinScore'])) {
                throw $this->fail('knowledge.ragMinScore', 'knowledge.ragMinScore must be a number');
            }
            $out['ragMinScore'] = max(0.0, min(1.0, (float) $knowledge['ragMinScore']));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function validateTools(mixed $tools, array $defaults): array
    {
        if (!is_array($tools) || array_is_list($tools)) {
            throw $this->fail('tools', 'tools must be an object');
        }
        $this->rejectUnknown(array_keys($tools), self::TOOL_KEYS, 'tools');

        $out = $defaults;
        foreach (['internet', 'files'] as $flag) {
            if (array_key_exists($flag, $tools)) {
                if (!is_bool($tools[$flag])) {
                    throw $this->fail('tools.'.$flag, 'tools.'.$flag.' must be a boolean');
                }
                $out[$flag] = $tools[$flag];
            }
        }
        if (array_key_exists('mcpServers', $tools)) {
            $out['mcpServers'] = $this->intList($tools['mcpServers'], 'tools.mcpServers');
        }
        if (array_key_exists('allow', $tools)) {
            $out['allow'] = $this->stringList($tools['allow'], 'tools.allow');
        }
        if (array_key_exists('deny', $tools)) {
            $out['deny'] = $this->stringList($tools['deny'], 'tools.deny');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function validateSkills(mixed $skills, array $defaults): array
    {
        if (!is_array($skills) || array_is_list($skills)) {
            throw $this->fail('skills', 'skills must be an object');
        }
        $this->rejectUnknown(array_keys($skills), self::SKILL_KEYS, 'skills');

        $out = $defaults;
        if (array_key_exists('allow', $skills)) {
            $out['allow'] = $this->stringList($skills['allow'], 'skills.allow');
        }
        if (array_key_exists('deny', $skills)) {
            $out['deny'] = $this->stringList($skills['deny'], 'skills.deny');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function validateParameters(mixed $parameters, array $defaults): array
    {
        if (!is_array($parameters) || array_is_list($parameters)) {
            throw $this->fail('parameters', 'parameters must be an object');
        }
        $this->rejectUnknown(array_keys($parameters), self::PARAMETER_KEYS, 'parameters');

        $out = $defaults;
        if (array_key_exists('temperature', $parameters)) {
            if (!is_int($parameters['temperature']) && !is_float($parameters['temperature'])) {
                throw $this->fail('parameters.temperature', 'parameters.temperature must be a number');
            }
            $out['temperature'] = max(0.0, min(2.0, (float) $parameters['temperature']));
        }
        if (array_key_exists('maxTokens', $parameters)) {
            if (!is_int($parameters['maxTokens']) && !is_float($parameters['maxTokens'])) {
                throw $this->fail('parameters.maxTokens', 'parameters.maxTokens must be a number');
            }
            $out['maxTokens'] = max(1, (int) $parameters['maxTokens']);
        }
        if (array_key_exists('language', $parameters)) {
            if (!is_string($parameters['language']) || '' === $parameters['language']) {
                throw $this->fail('parameters.language', 'parameters.language must be a non-empty string');
            }
            $out['language'] = $parameters['language'];
        }
        if (array_key_exists('responseSchema', $parameters)) {
            $schema = $parameters['responseSchema'];
            if (null !== $schema && (!is_array($schema) || array_is_list($schema))) {
                throw $this->fail('parameters.responseSchema', 'parameters.responseSchema must be an object or null');
            }
            $out['responseSchema'] = $schema;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function validateBehaviour(mixed $behaviour, array $defaults): array
    {
        if (!is_array($behaviour) || array_is_list($behaviour)) {
            throw $this->fail('behaviour', 'behaviour must be an object');
        }
        $this->rejectUnknown(array_keys($behaviour), self::BEHAVIOUR_KEYS, 'behaviour');

        $out = $defaults;
        if (array_key_exists('greeting', $behaviour)) {
            if (!is_string($behaviour['greeting'])) {
                throw $this->fail('behaviour.greeting', 'behaviour.greeting must be a string');
            }
            $out['greeting'] = $behaviour['greeting'];
        }
        if (array_key_exists('starterPrompts', $behaviour)) {
            $out['starterPrompts'] = $this->stringList($behaviour['starterPrompts'], 'behaviour.starterPrompts');
        }
        if (array_key_exists('memory', $behaviour)) {
            if ('user' !== $behaviour['memory']) {
                throw $this->fail('behaviour.memory', 'behaviour.memory accepts only "user"');
            }
            $out['memory'] = 'user';
        }

        return $out;
    }

    /**
     * @return array{events: list<array<string, mixed>>, schedules: list<array<string, mixed>>}
     */
    private function validateTriggers(mixed $triggers): array
    {
        if (!is_array($triggers) || array_is_list($triggers)) {
            throw $this->fail('triggers', 'triggers must be an object');
        }
        $this->rejectUnknown(array_keys($triggers), self::TRIGGER_KEYS, 'triggers');

        $seenIds = [];
        $events = [];
        if (isset($triggers['events'])) {
            $raw = $this->objectList($triggers['events'], 'triggers.events', self::EVENT_KEYS, static function (array $_item, string $_path): void {
            });
            foreach ($raw as $i => $item) {
                $events[] = $this->normalizeEvent($item, 'triggers.events.'.$i, $seenIds);
            }
        }

        $schedules = [];
        if (isset($triggers['schedules'])) {
            $raw = $this->objectList($triggers['schedules'], 'triggers.schedules', self::SCHEDULE_KEYS, static function (array $_item, string $_path): void {
            });
            foreach ($raw as $i => $item) {
                $schedules[] = $this->normalizeSchedule($item, 'triggers.schedules.'.$i, $seenIds);
            }
        }

        return ['events' => $events, 'schedules' => $schedules];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, true>  $seenIds
     *
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $item, string $path, array &$seenIds): array
    {
        $id = $this->requireTriggerId($item['id'] ?? null, $path.'.id', $seenIds);
        $kind = $item['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::EVENT_KINDS, true)) {
            throw $this->fail($path.'.kind', 'unknown event kind');
        }

        $out = [
            'id' => $id,
            'kind' => $kind,
            'enabled' => !array_key_exists('enabled', $item) || (bool) $item['enabled'],
        ];

        if (isset($item['instruction'])) {
            if (!is_string($item['instruction'])) {
                throw $this->fail($path.'.instruction', 'instruction must be a string');
            }
            $out['instruction'] = $item['instruction'];
        }

        if ('mail' === $kind) {
            $mailbox = $item['mailbox'] ?? null;
            if (!is_string($mailbox) || 1 !== preg_match('/^\d+:\d+$/', $mailbox)) {
                throw $this->fail($path.'.mailbox', 'mailbox must be "{ownerId}:{handlerId}"');
            }
            $out['mailbox'] = $mailbox;
            $hasRule = isset($item['rule']);
            $hasDepartment = isset($item['department']);
            if ($hasRule && $hasDepartment) {
                throw $this->fail($path, 'mail event cannot set both rule and department');
            }
            if ($hasDepartment) {
                if (!is_string($item['department']) || '' === trim((string) $item['department'])) {
                    throw $this->fail($path.'.department', 'department must be a non-empty string');
                }
                $out['department'] = trim((string) $item['department']);
            } else {
                $out['rule'] = $this->normalizeMailRule($item['rule'] ?? ['from' => [], 'contains' => [], 'match' => 'any'], $path.'.rule');
            }
        }

        if ('widget' === $kind) {
            $widget = $item['widget'] ?? null;
            if (!is_string($widget) || 1 !== preg_match('/^\d+:[A-Za-z0-9_-]+$/', $widget)) {
                throw $this->fail($path.'.widget', 'widget must be "{ownerId}:{widgetId}"');
            }
            $out['widget'] = $widget;
            if (isset($item['widgetDefaults'])) {
                if (!is_array($item['widgetDefaults']) || array_is_list($item['widgetDefaults'])) {
                    throw $this->fail($path.'.widgetDefaults', 'widgetDefaults must be an object');
                }
                $out['widgetDefaults'] = $item['widgetDefaults'];
            }
        }

        if ('whatsapp' === $kind && isset($item['number'])) {
            if (!is_string($item['number'])) {
                throw $this->fail($path.'.number', 'number must be a string');
            }
            $out['number'] = $item['number'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, true>  $seenIds
     *
     * @return array<string, mixed>
     */
    private function normalizeSchedule(array $item, string $path, array &$seenIds): array
    {
        $id = $this->requireTriggerId($item['id'] ?? null, $path.'.id', $seenIds);
        $name = $item['name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw $this->fail($path.'.name', 'name is required');
        }
        $tz = $item['tz'] ?? null;
        if (!is_string($tz) || '' === trim($tz)) {
            throw $this->fail($path.'.tz', 'tz is required');
        }
        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            throw $this->fail($path.'.tz', 'unknown timezone');
        }
        $instruction = $item['instruction'] ?? null;
        if (!is_string($instruction) || '' === trim($instruction)) {
            throw $this->fail($path.'.instruction', 'schedule instruction is required');
        }

        $cron = isset($item['cron']) && is_string($item['cron']) ? trim($item['cron']) : '';
        $every = isset($item['every']) && is_array($item['every']) ? $item['every'] : null;
        if ('' === $cron && null === $every) {
            throw $this->fail($path, 'schedule needs every or cron');
        }
        if (null !== $every) {
            $cron = $this->everyToCron($every, $path.'.every');
        }
        if ($this->cronTooFrequent($cron)) {
            throw $this->fail($path.'.cron', 'shortest interval is 15 minutes');
        }

        $out = [
            'id' => $id,
            'name' => trim($name),
            'cron' => $cron,
            'tz' => $tz,
            'instruction' => trim($instruction),
            'allowUnattended' => (bool) ($item['allowUnattended'] ?? false),
            'enabled' => !array_key_exists('enabled', $item) || (bool) $item['enabled'],
        ];
        if (null !== $every) {
            $out['every'] = $every;
        }

        return $out;
    }

    /**
     * @param array<string, true> $seenIds
     */
    private function requireTriggerId(mixed $id, string $path, array &$seenIds): string
    {
        if (!is_string($id) || 1 !== preg_match('/^[a-z0-9-]{2,32}$/', $id)) {
            throw $this->fail($path, 'id must match [a-z0-9-]{2,32}');
        }
        if (isset($seenIds[$id])) {
            throw $this->fail($path, 'duplicate trigger id');
        }
        $seenIds[$id] = true;

        return $id;
    }

    /**
     * @return array{from: list<string>, contains: list<string>, match: 'any'|'all'}
     */
    private function normalizeMailRule(mixed $rule, string $path): array
    {
        if (!is_array($rule) || array_is_list($rule)) {
            throw $this->fail($path, 'rule must be an object');
        }
        $match = $rule['match'] ?? 'any';
        if (!in_array($match, ['any', 'all'], true)) {
            throw $this->fail($path.'.match', 'match must be any or all');
        }

        return [
            'from' => $this->stringList($rule['from'] ?? [], $path.'.from'),
            'contains' => $this->stringList($rule['contains'] ?? [], $path.'.contains'),
            'match' => $match,
        ];
    }

    /**
     * @param array<string, mixed> $every
     */
    private function everyToCron(array $every, string $path): string
    {
        $unit = $every['unit'] ?? null;
        if (!is_string($unit) || !in_array($unit, ['hour', 'day', 'weekday', 'week', 'month'], true)) {
            throw $this->fail($path.'.unit', 'unit must be hour, day, weekday, week or month');
        }
        $at = is_string($every['at'] ?? null) ? $every['at'] : '08:00';
        if (1 !== preg_match('/^(\d{2}):(\d{2})$/', $at, $m)) {
            throw $this->fail($path.'.at', 'time must be HH:MM');
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            throw $this->fail($path.'.at', 'time must be HH:MM');
        }
        $on = is_string($every['on'] ?? null) ? strtolower($every['on']) : '';

        return match ($unit) {
            'hour' => sprintf('%d * * * *', $minute),
            'day' => sprintf('%d %d * * *', $minute, $hour),
            'weekday' => sprintf('%d %d * * 1-5', $minute, $hour),
            'week' => sprintf('%d %d * * %d', $minute, $hour, $this->weekdayToCron($on, $path.'.on')),
            'month' => sprintf('%d %d %d * *', $minute, $hour, $this->monthDay($on, $path.'.on')),
        };
    }

    private function weekdayToCron(string $on, string $path): int
    {
        $map = [
            'sun' => 0, 'sunday' => 0, '0' => 0, '7' => 0,
            'mon' => 1, 'monday' => 1, '1' => 1,
            'tue' => 2, 'tuesday' => 2, '2' => 2,
            'wed' => 3, 'wednesday' => 3, '3' => 3,
            'thu' => 4, 'thursday' => 4, '4' => 4,
            'fri' => 5, 'friday' => 5, '5' => 5,
            'sat' => 6, 'saturday' => 6, '6' => 6,
        ];
        if ('' === $on) {
            return 1;
        }
        if (!isset($map[$on])) {
            throw $this->fail($path, 'unknown weekday');
        }

        return $map[$on];
    }

    private function monthDay(string $on, string $path): int
    {
        $day = '' === $on ? 1 : (int) $on;
        if ($day < 1 || $day > 31) {
            throw $this->fail($path, 'month day must be 1-31');
        }

        return $day;
    }

    private function cronTooFrequent(string $cron): bool
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (5 !== count($parts)) {
            return true;
        }
        $minutes = $parts[0];
        if ('*' === $minutes) {
            return true;
        }
        if (1 === preg_match('#^\*/(\d+)$#', $minutes, $m)) {
            return (int) $m[1] < 15;
        }
        if (str_contains($minutes, ',')) {
            $vals = array_map(intval(...), explode(',', $minutes));
            sort($vals);
            for ($i = 1, $n = count($vals); $i < $n; ++$i) {
                if ($vals[$i] - $vals[$i - 1] < 15) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $keys
     * @param list<string>     $allowed
     */
    private function rejectUnknown(array $keys, array $allowed, string $prefix): void
    {
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
                throw $this->fail($path, sprintf('Unknown key "%s" in agent.v1', $path));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $path, ?string $pattern = null): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->fail($path, $path.' must be an array');
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!is_string($item)) {
                throw $this->fail($path.'.'.$i, $path.' entries must be strings');
            }
            if (null !== $pattern && 1 !== preg_match($pattern, $item)) {
                throw $this->fail($path.'.'.$i, $path.' entry does not match the expected shape');
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->fail($path, $path.' must be an array');
        }
        $out = [];
        foreach ($value as $i => $item) {
            if (!is_int($item) && !is_float($item)) {
                throw $this->fail($path.'.'.$i, $path.' entries must be integers');
            }
            $out[] = (int) $item;
        }

        return $out;
    }

    /**
     * @param list<string>                                 $allowedKeys
     * @param callable(array<string, mixed>, string): void $assertItem
     *
     * @return list<array<string, mixed>>
     */
    private function objectList(mixed $value, string $path, array $allowedKeys, callable $assertItem): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->fail($path, $path.' must be an array');
        }
        $out = [];
        foreach ($value as $i => $item) {
            $itemPath = $path.'.'.$i;
            if (!is_array($item) || array_is_list($item)) {
                throw $this->fail($itemPath, $itemPath.' must be an object');
            }
            $this->rejectUnknown(array_keys($item), $allowedKeys, $itemPath);
            $assertItem($item, $itemPath);
            $out[] = $item;
        }

        return $out;
    }

    private function fail(string $path, string $message): AgentDefinitionException
    {
        return new AgentDefinitionException($message, $path);
    }
}
