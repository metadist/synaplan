<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Service\Admin\SystemConfigService;

/**
 * The admin config schema as search entries. The same entries feed the
 * keyword matcher and the shared catalog rows (BUSERID = 0) of the index,
 * so "turn on groups" finds FEATURE_IAM_GROUPS_ENABLED by meaning.
 *
 * `inline` marks the settings the palette may switch in place: database
 * backed (the change applies without a restart), a toggle or a choice, never
 * a secret and never a field another surface edits.
 *
 * @phpstan-type SettingEntry array{key: string, tab: string, section: string, tabLabel: string, sectionLabel: string, description: string, type: string, options: list<string>, inline: bool}
 */
final readonly class SettingsCatalog
{
    public const KIND = 'setting';
    public const CATALOG_USER_ID = 0;

    public function __construct(
        private SystemConfigService $systemConfig,
    ) {
    }

    /**
     * @return array<string, SettingEntry>
     */
    public function entries(): array
    {
        $schema = $this->systemConfig->getSchema();
        $entries = [];
        foreach ($schema['fields'] as $key => $field) {
            $tab = $schema['tabs'][$field['tab']] ?? null;
            $entries[$key] = [
                'key' => $key,
                'tab' => $field['tab'],
                'section' => $field['section'],
                'tabLabel' => null === $tab ? $field['tab'] : $tab['label'],
                'sectionLabel' => $tab['sections'][$field['section']]['label'] ?? $field['section'],
                'description' => trim($field['description']),
                'type' => $field['type'],
                'options' => array_values($field['options'] ?? []),
                'inline' => 'database' === ($field['source'] ?? 'env')
                    && \in_array($field['type'], ['boolean', 'select'], true)
                    && !$field['sensitive']
                    && !isset($field['managedBy']),
            ];
        }

        return $entries;
    }

    /**
     * @return list<SearchDocument>
     */
    public function documents(): array
    {
        $documents = [];
        foreach ($this->entries() as $entry) {
            $documents[] = new SearchDocument(
                userId: self::CATALOG_USER_ID,
                kind: self::KIND,
                refId: $entry['key'],
                title: $entry['key'],
                body: $entry['tabLabel'].' – '.$entry['sectionLabel'].'. '.$entry['description'],
                updated: 0,
                lang: 'en',
            );
        }

        return $documents;
    }

    /** Changes whenever the schema (keys, labels, descriptions) changes. */
    public function fingerprint(): string
    {
        return sha1(implode("\n", array_map(static fn (SearchDocument $d): string => $d->refId.':'.$d->hash(), $this->documents())));
    }
}
