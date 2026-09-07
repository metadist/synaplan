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
    private const ROOT_KEYS = [
        'schema',
        'models',
        'knowledge',
        'tools',
        'skills',
        'parameters',
        'behaviour',
        'triggers',
    ];

    private const MODEL_KEYS = ['chat', 'vision', 'vectorize'];

    private const KNOWLEDGE_KEYS = ['ownFolder', 'folders', 'ragLimit', 'ragMinScore'];

    private const TOOL_KEYS = ['internet', 'files', 'mcpServers', 'allow', 'deny'];

    private const SKILL_KEYS = ['allow', 'deny'];

    private const PARAMETER_KEYS = ['temperature', 'maxTokens', 'language', 'responseSchema'];

    private const BEHAVIOUR_KEYS = ['greeting', 'starterPrompts', 'memory'];

    private const TRIGGER_KEYS = ['events', 'schedules'];

    /** S5 known event keys — accepted here so later sprints do not reopen the schema. */
    private const EVENT_KEYS = [
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

    private const SCHEDULE_KEYS = [
        'id',
        'name',
        'every',
        'cron',
        'tz',
        'instruction',
        'allowUnattended',
        'enabled',
    ];

    private const EVENT_KINDS = ['mail', 'whatsapp', 'widget', 'api', 'mcp', 'desktop', 'webhook'];

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

        $events = [];
        if (isset($triggers['events'])) {
            $events = $this->objectList($triggers['events'], 'triggers.events', self::EVENT_KEYS, function (array $item, string $path): void {
                if (isset($item['kind']) && (!is_string($item['kind']) || !in_array($item['kind'], self::EVENT_KINDS, true))) {
                    throw $this->fail($path.'.kind', 'unknown event kind');
                }
                if (isset($item['id']) && (!is_string($item['id']) || '' === $item['id'])) {
                    throw $this->fail($path.'.id', 'id must be a non-empty string');
                }
            });
        }

        $schedules = [];
        if (isset($triggers['schedules'])) {
            $schedules = $this->objectList($triggers['schedules'], 'triggers.schedules', self::SCHEDULE_KEYS, function (array $item, string $path): void {
                if (isset($item['id']) && (!is_string($item['id']) || '' === $item['id'])) {
                    throw $this->fail($path.'.id', 'id must be a non-empty string');
                }
            });
        }

        return ['events' => $events, 'schedules' => $schedules];
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
