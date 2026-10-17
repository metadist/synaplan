<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

/**
 * One admin system setting: how it renders (type, options, placeholder), its
 * default, whether it is a secret the API never returns, where its value
 * lives (the environment, or a BCONFIG row) and the tab and section that
 * show it. A field with another editor names it in `managedBy`.
 *
 * @phpstan-type FieldArray array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, source?: string, options?: list<string>, dbGroup?: string, dbKey?: string, encrypted?: bool, placeholder?: string, managedBy?: string}
 */
final readonly class FieldDefinition
{
    /**
     * @param list<string>|null $options
     */
    public function __construct(
        public string $type,
        public string $description,
        public string $default = '',
        public bool $sensitive = false,
        public ?DatabaseRow $row = null,
        public ?array $options = null,
        public ?string $placeholder = null,
        public ?string $managedBy = null,
        public string $tab = '',
        public string $section = '',
    ) {
    }

    /**
     * The same field shown on tab $tab, in section $section.
     */
    public function placedIn(string $tab, string $section): self
    {
        return new self(
            $this->type,
            $this->description,
            $this->default,
            $this->sensitive,
            $this->row,
            $this->options,
            $this->placeholder,
            $this->managedBy,
            $tab,
            $section,
        );
    }

    /**
     * The same field with one editor elsewhere, which keeps the value in
     * BCONFIG.
     */
    public function managedIn(string $editor): self
    {
        return new self(
            $this->type,
            $this->description,
            $this->default,
            $this->sensitive,
            $this->row ?? new DatabaseRow(),
            $this->options,
            $this->placeholder,
            $editor,
            $this->tab,
            $this->section,
        );
    }

    public function isStoredInDatabase(): bool
    {
        return null !== $this->row;
    }

    public function isEncrypted(): bool
    {
        return $this->row->encrypted ?? false;
    }

    public function group(): string
    {
        return $this->row->group ?? DatabaseRow::DEFAULT_GROUP;
    }

    /**
     * The BCONFIG setting that holds the value of the field named $key.
     */
    public function setting(string $key): string
    {
        return $this->row->key ?? $key;
    }

    /**
     * The field as the admin UI receives it.
     *
     * @return FieldArray
     */
    public function toArray(): array
    {
        $field = [
            'tab' => $this->tab,
            'section' => $this->section,
            'type' => $this->type,
            'sensitive' => $this->sensitive,
            'description' => $this->description,
            'default' => $this->default,
        ];
        if (null !== $this->row) {
            $field['source'] = 'database';
        }
        if (null !== $this->options) {
            $field['options'] = $this->options;
        }
        if (null !== $this->row?->group) {
            $field['dbGroup'] = $this->row->group;
        }
        if (null !== $this->row?->key) {
            $field['dbKey'] = $this->row->key;
        }
        if ($this->isEncrypted()) {
            $field['encrypted'] = true;
        }
        if (null !== $this->placeholder) {
            $field['placeholder'] = $this->placeholder;
        }
        if (null !== $this->managedBy) {
            $field['managedBy'] = $this->managedBy;
        }

        return $field;
    }
}
