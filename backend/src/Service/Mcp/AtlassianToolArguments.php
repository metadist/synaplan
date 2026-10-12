<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use Psr\Log\LoggerInterface;

/**
 * Atlassian MCP tools require cloudId and refuse to infer it. The planner
 * regularly sends the issue key and drops the site, which fails the call
 * with "Required at cloudId". Fill the site from a pasted Atlassian link,
 * or from the one site on the signed-in account when the message has none.
 */
final readonly class AtlassianToolArguments
{
    private const RESOURCES_TOOL = 'getAccessibleAtlassianResources';

    public function __construct(
        private McpClient $client,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{name: string, inputSchema: array<string, mixed>} $tool
     * @param array<string, mixed>                                   $arguments
     *
     * @return array<string, mixed>
     */
    public function complete(McpServerConfig $server, array $tool, array $arguments, string $sourceText): array
    {
        if (!$this->isAtlassian($server)) {
            return $arguments;
        }

        $properties = is_array($tool['inputSchema']['properties'] ?? null) ? $tool['inputSchema']['properties'] : [];
        $required = is_array($tool['inputSchema']['required'] ?? null) ? $tool['inputSchema']['required'] : [];
        $haystack = $sourceText."\n".$this->argumentText($arguments);

        if (isset($properties['issueIdOrKey']) && !$this->filled($arguments['issueIdOrKey'] ?? null)) {
            $key = $this->issueKey($haystack);
            if (null !== $key) {
                $arguments['issueIdOrKey'] = $key;
            }
        }

        if (isset($properties['cloudId']) && !$this->filled($arguments['cloudId'] ?? null)) {
            $knownKey = $this->filled($arguments['issueIdOrKey'] ?? null)
                ? strtoupper(trim((string) $arguments['issueIdOrKey']))
                : null;
            $cloudId = $this->cloudIdForIssue($haystack, $knownKey);
            $source = 'link';
            if (null === $cloudId && null === $knownKey && in_array('cloudId', $required, true) && !$this->textHasIssueUrl($haystack)) {
                $cloudId = $this->soleSite($server);
                $source = 'account';
            }
            if (null !== $cloudId) {
                $arguments['cloudId'] = $cloudId;
                $this->logger->info('AtlassianToolArguments: filled cloudId', [
                    'server_id' => $server->getId(),
                    'tool' => $tool['name'],
                    'source' => $source,
                ]);
            }
        }

        if ('getJiraIssue' === $tool['name']
            && isset($properties['view'])
            && !$this->filled($arguments['view'] ?? null)
            && $this->schemaOffers($properties['view'], 'full')
        ) {
            $arguments['view'] = 'full';
        }

        return $arguments;
    }

    private function isAtlassian(McpServerConfig $server): bool
    {
        $host = strtolower((string) parse_url($server->getUrl(), PHP_URL_HOST));

        return 'mcp.atlassian.com' === $host || str_ends_with($host, '.atlassian.net');
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && '' !== trim($value);
    }

    /**
     * When an issue key is already chosen, the site comes from the link for
     * that key. Another link in the same message is a different site and is
     * not used. With no key, the first Atlassian link in the message is used.
     */
    private function cloudIdForIssue(string $text, ?string $issueKey): ?string
    {
        if (null !== $issueKey && '' !== $issueKey) {
            $key = preg_quote($issueKey, '#');
            if (1 === preg_match('#https://([a-z0-9-]+)\.atlassian\.net/browse/'.$key.'(?:[/?\#\s]|$)#i', $text, $match)) {
                return 'https://'.strtolower($match[1]).'.atlassian.net';
            }
            if (1 === preg_match('#https://([a-z0-9-]+)\.atlassian\.net/\S*[?&]selectedIssue='.$key.'(?:&|\#|\s|$)#i', $text, $match)) {
                return 'https://'.strtolower($match[1]).'.atlassian.net';
            }

            return null;
        }

        return $this->cloudIdFromText($text);
    }

    private function textHasIssueUrl(string $text): bool
    {
        return 1 === preg_match('#https://[a-z0-9-]+\.atlassian\.net/(?:browse/[A-Z][A-Z0-9]+-\d+|\S*[?&]selectedIssue=)#i', $text);
    }

    /**
     * The first Atlassian link in the message. Used only when the call has
     * no issue key of its own.
     */
    private function cloudIdFromText(string $text): ?string
    {
        if (1 === preg_match('#https://([a-z0-9-]+)\.atlassian\.net/(?:browse/[A-Z][A-Z0-9]+-\d+|[^\\s]*selectedIssue=)#i', $text, $match)) {
            return 'https://'.strtolower($match[1]).'.atlassian.net';
        }
        if (1 === preg_match('#https://([a-z0-9-]+)\.atlassian\.net#i', $text, $match)) {
            return 'https://'.strtolower($match[1]).'.atlassian.net';
        }

        return null;
    }

    private function issueKey(string $text): ?string
    {
        if (1 === preg_match('#https://[a-z0-9-]+\.atlassian\.net/browse/([A-Z][A-Z0-9]+-\d+)#i', $text, $match)) {
            return strtoupper($match[1]);
        }
        if (1 === preg_match('#[?&]selectedIssue=([A-Z][A-Z0-9]+-\d+)#i', $text, $match)) {
            return strtoupper($match[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function argumentText(array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $value) {
            if (is_string($value) && '' !== $value) {
                $parts[] = $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * The signed-in account's only site. Several sites stay unresolved:
     * guessing would read the wrong Jira.
     */
    private function soleSite(McpServerConfig $server): ?string
    {
        try {
            $result = $this->client->callTool($server, self::RESOURCES_TOOL, []);
        } catch (McpClientException $e) {
            $this->logger->warning('AtlassianToolArguments: site lookup failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if ($result['isError']) {
            return null;
        }

        $sites = $this->sites($this->contentText($result['content']));
        if (1 !== count($sites)) {
            return null;
        }

        return $sites[0];
    }

    /**
     * @return list<string>
     */
    private function sites(string $text): array
    {
        $decoded = json_decode($text, true);
        $rows = null;
        if (is_array($decoded)) {
            if (is_array($decoded['data']['resources'] ?? null)) {
                $rows = $decoded['data']['resources'];
            } elseif (is_array($decoded['resources'] ?? null)) {
                $rows = $decoded['resources'];
            } elseif (array_is_list($decoded)) {
                $rows = $decoded;
            }
        }
        if (!is_array($rows)) {
            return [];
        }

        $sites = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = is_string($row['url'] ?? null) ? $this->cloudIdFromText($row['url']) : null;
            $id = is_string($row['cloudId'] ?? null) ? trim($row['cloudId']) : '';
            $value = $url ?? ('' !== $id ? $id : null);
            if (null !== $value) {
                $sites[$value] = $value;
            }
        }

        return array_values($sites);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function contentText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block['text'] ?? null) && '' !== $block['text']) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private function schemaOffers(mixed $property, string $value): bool
    {
        if (!is_array($property)) {
            return true;
        }
        $enum = $property['enum'] ?? null;
        if (!is_array($enum) || [] === $enum) {
            return true;
        }

        return in_array($value, $enum, true);
    }
}
