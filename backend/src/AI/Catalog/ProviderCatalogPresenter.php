<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * The public subset of a provider descriptor for signed-in users: what a
 * picker, an icon or a help hint needs — names, capabilities, branding and
 * which credential a key row asks for. Never a validation request, an
 * endpoint or an environment value.
 */
final readonly class ProviderCatalogPresenter
{
    public function __construct(
        private ProviderCatalog $catalog,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map($this->present(...), $this->catalog->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ProviderDescriptor $descriptor): array
    {
        $key = $descriptor->key;

        return [
            'name' => $descriptor->name,
            'displayName' => $descriptor->displayName,
            'capabilities' => array_map(static fn (ProviderCapability $c): string => $c->value, $descriptor->capabilities),
            'icon' => $descriptor->branding->icon,
            'iconComponent' => $descriptor->branding->iconComponent,
            'flag' => $descriptor->branding->flag,
            'local' => $descriptor->branding->local,
            'helpKey' => $descriptor->branding->helpKey,
            'helpUrl' => $descriptor->branding->helpUrl ?? $key?->consoleUrl,
            'helpIsDownload' => $descriptor->branding->helpIsDownload,
            'helpFields' => $descriptor->helpFields(),
            'key' => null === $key ? null : [
                'store' => $key->store->value,
                'envVar' => $key->envVar,
                'secretEnvVar' => $key->secretEnvVar,
                'consoleUrl' => $key->consoleUrl,
                'userCredential' => $key->userCredential->value,
                'servesChat' => $key->servesChat,
            ],
        ];
    }
}
