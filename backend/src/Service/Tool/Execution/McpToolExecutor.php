<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;

/**
 * One outbound MCP tool call. The result text matches the caller that
 * used to format it; fetch and action runners still wrap transport
 * failures in their own sentence.
 */
final readonly class McpToolExecutor implements ToolExecutorInterface
{
    public function __construct(
        private McpClient $client,
        private McpServerConfigRepository $servers,
    ) {
    }

    public function supports(ToolDescriptor $tool): bool
    {
        return ToolSource::Mcp === $tool->source;
    }

    public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
    {
        $serverId = (int) ($tool->meta['serverId'] ?? 0);
        $mcpTool = is_string($tool->meta['tool'] ?? null) ? $tool->meta['tool'] : '';
        $userId = (int) ($invocation->caller->userId ?? 0);
        $server = $serverId > 0 ? $this->servers->findByIdAndUser($serverId, $userId) : null;
        if (null === $server || !$server->isEnabled() || '' === $mcpTool) {
            return $this->unavailable($invocation, $serverId, $mcpTool);
        }

        try {
            $call = $this->client->callTool($server, $mcpTool, $invocation->arguments);
        } catch (McpClientException $e) {
            return new ToolExecution($e->getMessage(), true, 'mcp', $tool->sideEffect->value, details: [
                'transport' => true,
                'httpStatus' => $e->httpStatus,
                'content' => [],
            ]);
        }

        $content = $call['content'];
        $toolError = $call['isError'];
        if (ToolChannel::RESUME === $invocation->caller->channel && $toolError) {
            return new ToolExecution(
                sprintf('MCP tool %s reported an error: %s', $mcpTool, $this->plain($content)),
                true,
                'mcp',
                $tool->sideEffect->value,
                details: ['content' => $content, 'toolError' => true],
            );
        }

        return new ToolExecution($this->format($invocation->caller->channel, $content), $toolError, 'mcp', $tool->sideEffect->value, details: [
            'content' => $content,
            'toolError' => $toolError,
            'ref' => 'mcp:'.$serverId.':'.$mcpTool,
            'summary' => $this->plain($content),
        ]);
    }

    private function unavailable(ToolInvocation $invocation, int $serverId, string $mcpTool): ToolExecution
    {
        $text = ToolChannel::RESUME === $invocation->caller->channel
            ? sprintf('MCP server %d for tool %s is not available', $serverId, $invocation->toolName)
            : 'MCP server is not available.';

        return new ToolExecution($text, true, 'mcp', 'read', details: [
            'unavailable' => true,
            'tool' => $mcpTool,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function format(string $channel, array $content): string
    {
        if (ToolChannel::MESSAGES === $channel) {
            return $this->gateway($content);
        }
        if (ToolChannel::OPENAI === $channel || ToolChannel::SAVED_TASK === $channel) {
            return $this->lines($content, ToolChannel::SAVED_TASK === $channel ? "\n" : "\n\n", false);
        }

        return $this->plain($content);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function gateway(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ('text' === $type && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif (is_array($block['resource'] ?? null) && is_string($block['resource']['text'] ?? null)) {
                $parts[] = $block['resource']['text'];
            } else {
                $encoded = json_encode($block, \JSON_INVALID_UTF8_SUBSTITUTE);
                if (false !== $encoded) {
                    $parts[] = $encoded;
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function plain(array $content): string
    {
        return $this->lines($content, "\n\n", true);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function lines(array $content, string $glue, bool $includeResource): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null) && '' !== $block['text']) {
                $parts[] = $block['text'];
            } elseif ($includeResource && is_array($block['resource'] ?? null) && is_string($block['resource']['text'] ?? null)) {
                $parts[] = $block['resource']['text'];
            }
        }

        return trim(implode($glue, $parts));
    }
}
