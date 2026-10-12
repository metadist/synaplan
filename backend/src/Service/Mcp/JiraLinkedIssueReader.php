<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Reads a pasted Jira issue through the user's Atlassian MCP connection.
 *
 * A public fetch of `*.atlassian.net/browse/KEY` only sees the login screen.
 * `getJiraIssue` needs the site (`cloudId`) as well as the issue key. The
 * planner often drops the site, so a pasted epic, ticket or bug is loaded
 * here before any public reader runs.
 */
final readonly class JiraLinkedIssueReader
{
    private const MAX_ISSUE_CHARS = 12000;

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpClient $client,
        private McpClientConfig $clientConfig,
        private AtlassianToolArguments $arguments,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>   $urls
     * @param list<int>|null $serverAllowlist null = every enabled connection; a list limits which connection may be called
     * @param callable|null  $onReady         fired once an issue and an allowed connection are known, before the first remote call
     */
    public function read(array $urls, int $userId, ?array $serverAllowlist = null, ?callable $onReady = null): JiraLinkedIssueRead
    {
        $issues = $this->jiraIssues($urls);
        if ([] === $issues || $userId <= 0 || !$this->clientConfig->isClientEnabled($userId)) {
            return new JiraLinkedIssueRead([]);
        }

        $server = $this->atlassianServer($userId, $serverAllowlist);
        if (null === $server) {
            return new JiraLinkedIssueRead([]);
        }

        if (null !== $onReady) {
            $onReady();
        }

        try {
            $tools = $this->client->listTools($server);
        } catch (McpClientException $e) {
            $this->logger->warning('JiraLinkedIssueReader: tool list failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return new JiraLinkedIssueRead($this->failures($issues, $server->getName(), $this->plainError($e->getMessage())));
        }

        $tool = $this->issueTool($tools);
        if (null === $tool) {
            return new JiraLinkedIssueRead($this->failures(
                $issues,
                $server->getName(),
                'This connection does not offer a tool that can open a Jira issue.',
            ));
        }

        $read = [];
        foreach ($issues as $issue) {
            $read[] = $this->readOne($server, $tool, $issue);
        }

        return new JiraLinkedIssueRead($read);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<array{url: string, site: string, key: string}>
     */
    private function jiraIssues(array $urls): array
    {
        $issues = [];
        foreach ($urls as $url) {
            $match = [];
            $matched = 1 === preg_match('#^https://([a-z0-9-]+)\.atlassian\.net/browse/([A-Z][A-Z0-9]+-\d+)(?:[/?\#].*)?$#i', $url, $match)
                || 1 === preg_match('#^https://([a-z0-9-]+)\.atlassian\.net/\S*[?&]selectedIssue=([A-Z][A-Z0-9]+-\d+)#i', $url, $match);
            if (!$matched) {
                continue;
            }
            $issues[] = [
                'url' => $url,
                'site' => strtolower($match[1]),
                'key' => strtoupper($match[2]),
            ];
        }

        return $issues;
    }

    /**
     * A signed-in connection wins over a pasted token.
     *
     * @param list<int>|null $serverAllowlist
     */
    private function atlassianServer(int $userId, ?array $serverAllowlist): ?McpServerConfig
    {
        $signedIn = null;
        $token = null;
        $site = null;
        foreach ($this->servers->findEnabledByUser($userId) as $server) {
            if (null !== $serverAllowlist && !in_array((int) $server->getId(), $serverAllowlist, true)) {
                continue;
            }
            $host = strtolower((string) parse_url($server->getUrl(), PHP_URL_HOST));
            if ('mcp.atlassian.com' === $host) {
                if ($server->isOAuth()) {
                    $signedIn ??= $server;
                } else {
                    $token ??= $server;
                }
            } elseif (str_ends_with($host, '.atlassian.net')) {
                $site ??= $server;
            }
        }

        return $signedIn ?? $token ?? $site;
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return array{name: string, inputSchema: array<string, mixed>}|null
     */
    private function issueTool(array $tools): ?array
    {
        foreach ($tools as $tool) {
            if ('getjiraissue' === strtolower($tool['name'])) {
                return ['name' => $tool['name'], 'inputSchema' => $tool['inputSchema']];
            }
        }

        return null;
    }

    /**
     * @param array{name: string, inputSchema: array<string, mixed>} $tool
     * @param array{url: string, site: string, key: string}          $issue
     *
     * @return array{url: string, success: bool, serverName: string, text: string}
     */
    private function readOne(McpServerConfig $server, array $tool, array $issue): array
    {
        $arguments = $this->arguments->complete($server, $tool, ['issueIdOrKey' => $issue['key']], $issue['url']);
        try {
            $result = $this->client->callTool($server, $tool['name'], $arguments);
        } catch (McpClientException $e) {
            $this->logger->warning('JiraLinkedIssueReader: issue read failed', [
                'server_id' => $server->getId(),
                'tool' => $tool['name'],
                'error' => $e->getMessage(),
            ]);

            return [
                'url' => $issue['url'],
                'success' => false,
                'serverName' => $server->getName(),
                'text' => $this->plainError($e->getMessage()),
            ];
        }

        $text = $this->contentText($result['content']);
        if ($result['isError'] || '' === trim($text)) {
            return [
                'url' => $issue['url'],
                'success' => false,
                'serverName' => $server->getName(),
                'text' => $this->plainError('' !== trim($text) ? $text : 'The connection returned no issue content.'),
            ];
        }

        return [
            'url' => $issue['url'],
            'success' => true,
            'serverName' => $server->getName(),
            'text' => mb_substr($text, 0, self::MAX_ISSUE_CHARS),
        ];
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

    private function plainError(string $raw): string
    {
        $decoded = json_decode($raw, true);
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : trim($raw);
        if ('' === $message) {
            $message = 'The Jira connection could not open this issue.';
        }

        return $message;
    }

    /**
     * @param list<array{url: string, site: string, key: string}> $issues
     *
     * @return list<array{url: string, success: bool, serverName: string, text: string}>
     */
    private function failures(array $issues, string $serverName, string $text): array
    {
        $failed = [];
        foreach ($issues as $issue) {
            $failed[] = [
                'url' => $issue['url'],
                'success' => false,
                'serverName' => $serverName,
                'text' => $text,
            ];
        }

        return $failed;
    }
}
