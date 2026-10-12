<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Issues from one turn that were handed to a connected Jira MCP server
 * instead of the public web reader.
 */
final readonly class JiraLinkedIssueRead
{
    /**
     * @param list<array{url: string, success: bool, serverName: string, text: string}> $issues
     */
    public function __construct(public array $issues)
    {
    }

    public function attempted(): bool
    {
        return [] !== $this->issues;
    }

    public function successCount(): int
    {
        return count(array_filter($this->issues, static fn (array $issue): bool => $issue['success']));
    }

    /**
     * @return list<string>
     */
    public function handledUrls(): array
    {
        return array_map(static fn (array $issue): string => $issue['url'], $this->issues);
    }

    /**
     * @param list<string> $urls
     */
    public function coversAll(array $urls): bool
    {
        if ([] === $urls || [] === $this->issues) {
            return false;
        }

        $handled = array_fill_keys($this->handledUrls(), true);
        foreach ($urls as $url) {
            if (!isset($handled[$url])) {
                return false;
            }
        }

        return true;
    }

    public function prompt(): string
    {
        if ([] === $this->issues) {
            return '';
        }

        $sections = [];
        foreach ($this->issues as $issue) {
            $header = sprintf('--- URL: %s ---', $issue['url']);
            $header .= sprintf("\nConnection: %s", $issue['serverName']);
            if ($issue['success']) {
                $sections[] = $header."\nContent:\n".$issue['text'];
                continue;
            }
            $sections[] = $header."\nStatus: NOT READ. ".$issue['text'];
        }

        return "## Linked Jira issues\n"
            .'The user pasted a Jira link. The system read it through their connected Jira account, not the public web. '
            .'Answer from the content below. If an issue is marked NOT READ, say that in one sentence and include the reason. '
            ."Do not ask them to paste or upload the issue, and do not describe the public login screen.\n\n"
            .implode("\n\n", $sections);
    }
}
