<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugHealth;

/**
 * Optional live reachability check. Built-in adapters implement this;
 * third-party plugins that only implement {@see WebSearchProviderInterface}
 * keep loading. Admin badges then show "Key stored — not verified".
 */
interface WebSearchLiveProbeInterface
{
    public function probe(): PlugHealth;
}
