<?php

declare(strict_types=1);

namespace App\Service\Agent\Definition;

/**
 * Validated `agent.v1` document. Always a complete object — missing
 * sections are filled from {@see defaults()} by the validator.
 */
final readonly class AgentDefinition
{
    public const SCHEMA = 'agent.v1';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public array $data)
    {
    }

    public static function defaults(): self
    {
        return new self([
            'schema' => self::SCHEMA,
            'models' => [
                'chat' => null,
                'vision' => null,
                'vectorize' => null,
            ],
            'knowledge' => [
                'ownFolder' => true,
                'folders' => [],
                'includeUserFiles' => false,
                'ragLimit' => 8,
                'ragMinScore' => 0.6,
            ],
            'tools' => [
                'internet' => true,
                'files' => true,
                'mcpServers' => [],
                'allow' => [],
                'deny' => [],
            ],
            'skills' => [
                'allow' => [],
                'deny' => [],
            ],
            'parameters' => [
                'temperature' => 0.7,
                'maxTokens' => 4000,
                'language' => 'auto',
                'responseSchema' => null,
            ],
            'behaviour' => [
                'greeting' => '',
                'starterPrompts' => [],
                'memory' => 'user',
            ],
            'triggers' => [
                'events' => [],
                'schedules' => [],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function ownFolderEnabled(): bool
    {
        $knowledge = $this->data['knowledge'] ?? [];

        return is_array($knowledge) && true === ($knowledge['ownFolder'] ?? true);
    }

    /**
     * Additional owner folders (BFILES group keys) this assistant searches.
     *
     * @return list<string>
     */
    public function knowledgeFolders(): array
    {
        $knowledge = $this->data['knowledge'] ?? [];
        $folders = is_array($knowledge) ? ($knowledge['folders'] ?? []) : [];
        if (!is_array($folders)) {
            return [];
        }

        return array_values(array_filter($folders, static fn ($f): bool => is_string($f) && '' !== $f));
    }

    /**
     * Whether the talking user's own files are searched alongside the
     * assistant's folders. Off by default: an assistant is a recipe over its
     * owner's knowledge, not a window into the reader's files.
     */
    public function includeUserFiles(): bool
    {
        $knowledge = $this->data['knowledge'] ?? [];

        return is_array($knowledge) && true === ($knowledge['includeUserFiles'] ?? false);
    }

    public function ragLimit(): int
    {
        $knowledge = $this->data['knowledge'] ?? [];

        return is_array($knowledge) ? max(1, min(50, (int) ($knowledge['ragLimit'] ?? 8))) : 8;
    }

    public function ragMinScore(): float
    {
        $knowledge = $this->data['knowledge'] ?? [];

        return is_array($knowledge) ? max(0.0, min(1.0, (float) ($knowledge['ragMinScore'] ?? 0.6))) : 0.6;
    }

    /**
     * @return array<string, string|null>
     */
    public function models(): array
    {
        $models = $this->data['models'] ?? [];
        if (!is_array($models)) {
            return [];
        }

        $out = [];
        foreach ($models as $capability => $key) {
            if (!is_string($capability)) {
                continue;
            }
            $out[$capability] = is_string($key) ? $key : null;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function tools(): array
    {
        $tools = $this->data['tools'] ?? [];

        return is_array($tools) ? $tools : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function skills(): array
    {
        $skills = $this->data['skills'] ?? [];

        return is_array($skills) ? $skills : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        $parameters = $this->data['parameters'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }
}
