<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Plug\WebSearch\Adapter\BraveSearchAdapter;
use App\Plug\WebSearch\WebSearchGateway;
use App\Service\Search\BraveSearchService;

/**
 * Wraps a BraveSearchService mock so existing caller tests keep asserting
 * on search() / isEnabled() without constructing the full registry.
 */
final class WebSearchGatewayFactory
{
    public static function fromBrave(BraveSearchService $brave): WebSearchGateway
    {
        return WebSearchGateway::forProvider(new BraveSearchAdapter($brave));
    }
}
