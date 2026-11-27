<?php

declare(strict_types=1);

namespace App\Service\Plugin;

/**
 * Plugins that ship in the image and are installed for every verified account.
 *
 * Synaads is not in this list: it still lives in its own repository and is
 * gitignored here. An operator can name extra plugins in DEFAULT_USER_PLUGINS;
 * those stay a deployment choice and are not installed by the seeder.
 */
final class BundledPlugins
{
    /** @var list<string> */
    public const NAMES = ['synaform', 'synamail'];

    private function __construct()
    {
    }
}
