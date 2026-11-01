<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * How a provider is drawn and explained in the user interface.
 */
final readonly class ProviderBranding
{
    /**
     * @param string       $icon           an Iconify id; the fallback glyph every consumer can draw
     * @param ?string      $iconComponent  a bundled Vue glyph that replaces the Iconify id where the app has it (`GroqIcon`)
     * @param string       $flag           a `circle-flags:<cc>` id, `mdi:map-marker` for a self-hosted engine, `circle-flags:un` when unknown
     * @param bool         $local          runs on the operator's machine: pin badge, no jurisdiction
     * @param ?string      $helpKey        `admin.providerHelp.<helpKey>` in the locale files; null = no help hint
     * @param ?string      $helpUrl        the page the hint links to; null = the key's console URL
     * @param bool         $helpIsDownload the help page is a download / setup flow rather than a key console
     * @param list<string> $helpFields     system-config field keys that show this provider's hint; empty = the key's env var
     */
    public function __construct(
        public string $icon,
        public ?string $iconComponent = null,
        public string $flag = 'circle-flags:un',
        public bool $local = false,
        public ?string $helpKey = null,
        public ?string $helpUrl = null,
        public bool $helpIsDownload = false,
        public array $helpFields = [],
    ) {
    }

    /**
     * @param list<string> $helpFields
     */
    public static function local(string $icon, ?string $iconComponent = null, ?string $helpKey = null, ?string $helpUrl = null, bool $helpIsDownload = false, array $helpFields = []): self
    {
        return new self($icon, $iconComponent, 'mdi:map-marker', true, $helpKey, $helpUrl, $helpIsDownload, $helpFields);
    }
}
