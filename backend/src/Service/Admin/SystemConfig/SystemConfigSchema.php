<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The admin settings schema, assembled from the tabs in tag order: the
 * structure the admin UI renders (tabs, their sections, each section's field
 * keys) and every field placed in its tab and section, listed in that order.
 *
 * Instance provider keys have exactly one editor, AI infrastructure ›
 * Providers & keys ({@see ProviderKeyStore}). A field whose key is an env
 * var the {@see ProviderKeyCatalog} manages stays readable here, so the
 * response shape does not change, but is marked `managedBy`: the UI renders a
 * pointer instead of an input and a write is refused.
 */
final readonly class SystemConfigSchema
{
    /** @var array<string, array{label: string, sections: array<string, array{label: string, fields: list<string>}>}> */
    private array $tabs;

    /** @var array<string, FieldDefinition> */
    private array $fields;

    /**
     * @param iterable<SystemConfigTab> $tabs
     */
    public function __construct(
        #[AutowireIterator(SystemConfigTab::TAG)]
        iterable $tabs,
    ) {
        $structure = [];
        $fields = [];
        foreach ($tabs as $tab) {
            $sections = [];
            foreach ($tab->sections() as $sectionId => $section) {
                $sections[$sectionId] = ['label' => $section->label, 'fields' => array_keys($section->fields)];
                foreach ($section->fields as $key => $field) {
                    if (isset($fields[$key])) {
                        throw new \LogicException(sprintf('Setting %s is declared twice: in %s / %s and in %s / %s.', $key, $fields[$key]->tab, $fields[$key]->section, $tab->id(), $sectionId));
                    }
                    $fields[$key] = $field->placedIn($tab->id(), $sectionId);
                }
            }
            $structure[$tab->id()] = ['label' => $tab->label(), 'sections' => $sections];
        }

        foreach (ProviderKeyCatalog::managedEnvVars() as $envVar) {
            if (isset($fields[$envVar])) {
                // Stored encrypted in BCONFIG by the store and applied per call.
                $fields[$envVar] = $fields[$envVar]->managedIn(ProviderKeyCatalog::MANAGED_BY);
            }
        }

        $this->tabs = $structure;
        $this->fields = $fields;
    }

    /**
     * @return array<string, array{label: string, sections: array<string, array{label: string, fields: list<string>}>}>
     */
    public function tabs(): array
    {
        return $this->tabs;
    }

    /**
     * @return array<string, FieldDefinition>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $key): ?FieldDefinition
    {
        return $this->fields[$key] ?? null;
    }
}
