<?php

// OP0 spike — not product code.
//
// Drives Synaplan's real App\Service\Mcp\McpClient against MCP servers on the
// Docker network. The only substitution is the SSRF guard: it is replaced by
// the guard's own operator-network mode, the allowance an administrator-added
// server would get (00_master_plan.md §0 #5). Run inside the backend
// container (run.sh copies it there):
//
//   php mcp_client_probe.php                                   # the three spike servers
//   php mcp_client_probe.php <url> <tool> '<json arguments>'   # any other server, repeatable

declare(strict_types=1);

require '/var/www/backend/vendor/autoload.php';

// Must run before SsrfGuard and McpClientConfig are autoloaded.
DG\BypassFinals::enable(bypassReadOnly: false);

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;

final readonly class OperatorNetworkSsrfGuard extends SsrfGuard
{
    public function isBlockedUrl(string $url): bool
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);
        $ips = gethostbynamel($host) ?: [];
        if ([] === $ips) {
            return true;
        }
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip, allowOperatorNetwork: true)) {
                return true;
            }
        }

        return false;
    }
}

final readonly class FixedTimeoutConfig extends McpClientConfig
{
    public function __construct()
    {
    }

    public function nodeTimeoutSeconds(): int
    {
        return 15;
    }
}

$targets = [
    ['Python (mcp 2.3.0, MCPServer)', 'http://py-tool:8000/mcp', 'word_stats', ['text' => 'Synaplan plugins in any language. Really!']],
    ['Node.js (@modelcontextprotocol/sdk 1.32.1)', 'http://node-tool:8000/mcp', 'slugify', ['text' => 'Hello Plugin World from Node']],
    ['Go (modelcontextprotocol/go-sdk 1.8.0)', 'http://go-tool:8000/mcp', 'reverse_text', ['text' => 'synaplan']],
];

$args = array_slice($argv, 1);
if ([] !== $args) {
    if (0 !== count($args) % 3) {
        fwrite(\STDERR, "Usage: php mcp_client_probe.php [<url> <tool> '<json arguments>']...\n");
        exit(2);
    }
    $targets = [];
    foreach (array_chunk($args, 3) as [$url, $tool, $json]) {
        $decoded = json_decode($json, true);
        $targets[] = [$url, $url, $tool, is_array($decoded) ? $decoded : []];
    }
}

$strictGuard = new SsrfGuard();
$client = new McpClient(
    HttpClient::create(),
    new OperatorNetworkSsrfGuard(),
    new EncryptionService('unused-for-auth-mode-none', new NullLogger()),
    new FixedTimeoutConfig(),
    new NullLogger(),
);

foreach ($targets as [$label, $url, $tool, $toolArgs]) {
    echo "=== {$label}\n";
    echo '    URL: '.$url."\n";
    echo '    Today (SsrfGuard::isBlockedUrl): '.($strictGuard->isBlockedUrl($url) ? 'BLOCKED' : 'allowed')."\n";

    $server = (new McpServerConfig())
        ->setUserId(2)
        ->setName($label)
        ->setUrl($url)
        ->setAuthMode(McpServerConfig::AUTH_MODE_NONE);

    try {
        foreach ($client->listTools($server) as $listed) {
            echo sprintf(
                "    tools/list -> %s: %s | inputSchema=%s | annotations=%s\n",
                $listed['name'],
                $listed['description'],
                json_encode($listed['inputSchema'], \JSON_UNESCAPED_SLASHES),
                json_encode($listed['annotations'], \JSON_UNESCAPED_SLASHES),
            );
        }
        $result = $client->callTool($server, $tool, $toolArgs);
        echo sprintf(
            "    tools/call %s(%s) -> isError=%s content=%s\n",
            $tool,
            json_encode($toolArgs, \JSON_UNESCAPED_UNICODE),
            $result['isError'] ? 'true' : 'false',
            json_encode($result['content'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );
    } catch (McpClientException $e) {
        echo '    FAILED: '.$e->getMessage()."\n";
    }
    echo "\n";
}
