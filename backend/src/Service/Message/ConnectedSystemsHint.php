<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Multitask\MultitaskRoutingConfig;

/**
 * Tells the sorter which external systems the user has connected.
 *
 * The sorter's BMULTI vote decides whether the planner runs at all — and only
 * the planner can emit an `mcp_fetch` step. Its prompt describes "looking
 * something up in one of the user's connected systems" abstractly, so a
 * request like "check whether my Backblaze bucket is reachable" was voted a
 * plain single-step question: the planner never ran, the connected server was
 * never asked, and the answer said no data source was available although one
 * was configured. Naming the connections (and the tools already discovered
 * for them) lets the sorter recognise such a lookup.
 *
 * Gated exactly like the planner's sub-catalog (MCP client on, `mcp_fetch`
 * routing flag on, server enabled); without a connection the block is empty
 * and the sorter prompt is byte-for-byte unchanged. Tool names come from the
 * registry CACHE only — the sorter sits on every turn's critical path and must
 * never wait for a `tools/list` round-trip.
 */
final readonly class ConnectedSystemsHint
{
    /** Keep the sorter prompt small: a handful of tool names is enough of a hint. */
    private const MAX_TOOLS_PER_SERVER = 8;

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpClientConfig $clientConfig,
        private MultitaskRoutingConfig $routingConfig,
        private McpToolRegistry $toolRegistry,
    ) {
    }

    /**
     * Prompt block for the sorter, or '' when the user has nothing connected.
     */
    public function renderForSorter(?int $userId): string
    {
        try {
            $lines = $this->connectionLines($userId);
        } catch (\Throwable) {
            // A hint must never break classification — degrade to the plain prompt.
            return '';
        }
        if ([] === $lines) {
            return '';
        }

        return "\n\n## Connected systems of this user\n"
            ."The user has connected the systems listed below. Any question or task that needs\n"
            ."data from one of them — status, reachability, listings, lookups, checks, contents —\n"
            ."counts as \"looking something up in one of the user's connected systems\", including\n"
            ."when the user names the system, its vendor, or the kind of thing it stores (a bucket,\n"
            ."a ticket, a page, a customer record). For such a request set BMULTI to 1 so a data\n"
            ."step is planned, and leave BWEBSEARCH at 0: the answer comes from the connected\n"
            ."system, not from the web.\n"
            .implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function connectionLines(?int $userId): array
    {
        if (null === $userId || $userId <= 0) {
            return [];
        }
        if (!$this->clientConfig->isClientEnabled($userId)
            || !$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED, $userId, false)) {
            return [];
        }

        $lines = [];
        foreach ($this->servers->findEnabledByUser($userId) as $server) {
            $lines[] = $this->describe($server);
        }

        return $lines;
    }

    private function describe(McpServerConfig $server): string
    {
        $line = sprintf('- "%s" (connected data source)', trim($server->getName()));

        $names = [];
        foreach ($this->toolRegistry->cachedToolsFor($server) ?? [] as $tool) {
            if ('' !== $tool['name']) {
                $names[] = $tool['name'];
            }
        }
        if ([] !== $names) {
            $shown = array_slice($names, 0, self::MAX_TOOLS_PER_SERVER);
            $line .= ' — tools: '.implode(', ', $shown).(count($names) > count($shown) ? ', …' : '');
        }

        return $line;
    }
}
