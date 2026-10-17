<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

/**
 * A section of a settings tab: its heading and its fields, in the order the
 * admin sees them.
 */
final readonly class ConfigSection
{
    /**
     * @param array<string, FieldDefinition> $fields by setting key
     */
    public function __construct(
        public string $label,
        public array $fields,
    ) {
    }
}
