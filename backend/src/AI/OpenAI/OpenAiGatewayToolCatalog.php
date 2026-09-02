<?php

declare(strict_types=1);

namespace App\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\Tool\OpenAiToolShapes;
use App\Entity\User;
use App\Service\Mcp\McpClientConfig;
use App\Service\MessagesGateway\MessagesGatewayConfig;

/**
 * OpenAI Chat Completions policy for Synaplan-owned tools.
 *
 * Deliberately different from {@see GatewayToolCatalog}:
 * - MCP is injected when {@see McpClientConfig::isClientEnabled()} and the
 *   user has at least one connected server — not when
 *   MESSAGES_GATEWAY.MCP_TOOLS_ENABLED is on (that default is off).
 * - MCP is injected even when the client already sent tools.
 * - web_search is injected whenever Brave is available and
 *   WEB_SEARCH_MODE is not `off` — the client does not need an Anthropic
 *   server-tool declaration.
 *
 * Name collision: a Synaplan declaration whose function name is already in
 * the client's tools[] is skipped (the client owns that name).
 *
 * @phpstan-type DispatchEntry array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
 * @phpstan-type CatalogSnapshot array{
 *     tools: list<array<string, mixed>>,
 *     dispatch: array<string, DispatchEntry>
 * }
 */
final readonly class OpenAiGatewayToolCatalog
{
    public function __construct(
        private McpToolCatalogAdapter $mcpCatalogAdapter,
        private McpClientConfig $mcpClientConfig,
        private WebSearchTool $webSearchTool,
        private MessagesGatewayConfig $messagesGatewayConfig,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $clientTools OpenAI function declarations
     *
     * @return CatalogSnapshot
     */
    public function build(User $user, array $clientTools): array
    {
        $taken = $this->clientFunctionNames($clientTools);
        $tools = [];
        $dispatch = [];

        foreach ($this->mcpDeclarations((int) $user->getId()) as $row) {
            $name = $row['tool']['name'];
            if (isset($taken[$name]) || isset($dispatch[$name])) {
                continue;
            }
            $tools[] = $row['openai'];
            $dispatch[$name] = $row['dispatch'];
            $taken[$name] = true;
        }

        $web = $this->webSearchDeclaration($user, $taken);
        if (null !== $web) {
            $tools[] = $web['openai'];
            $dispatch[WebSearchTool::NAME] = $web['dispatch'];
        }

        return ['tools' => $tools, 'dispatch' => $dispatch];
    }

    /**
     * @return list<array{tool: array<string, mixed>, openai: array<string, mixed>, dispatch: DispatchEntry}>
     */
    private function mcpDeclarations(int $userId): array
    {
        if ($userId <= 0 || !$this->mcpClientConfig->isClientEnabled($userId)) {
            return [];
        }

        $mcp = $this->mcpCatalogAdapter->toAnthropicTools($userId, includeMutating: false);
        if ([] === $mcp['tools']) {
            return [];
        }

        $out = [];
        $openaiTools = OpenAiToolShapes::toChatCompletionsTools($mcp['tools']);
        foreach ($mcp['tools'] as $i => $tool) {
            $name = $tool['name'];
            if ('' === $name || !isset($mcp['dispatch'][$name])) {
                continue;
            }
            $out[] = [
                'tool' => $tool,
                'openai' => $openaiTools[$i] ?? OpenAiToolShapes::toChatCompletionsTools([$tool])[0],
                'dispatch' => [
                    'kind' => GatewayToolCatalog::KIND_MCP,
                    'serverId' => $mcp['dispatch'][$name]['serverId'],
                    'tool' => $mcp['dispatch'][$name]['tool'],
                    'annotations' => $mcp['dispatch'][$name]['annotations'],
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, true> $taken
     *
     * @return array{openai: array<string, mixed>, dispatch: DispatchEntry}|null
     */
    private function webSearchDeclaration(User $user, array $taken): ?array
    {
        if (isset($taken[WebSearchTool::NAME])) {
            return null;
        }
        if (MessagesGatewayConfig::WEB_SEARCH_OFF === $this->messagesGatewayConfig->webSearchMode($user->getId())) {
            return null;
        }
        if (!$this->webSearchTool->isAvailable()) {
            return null;
        }

        $openai = OpenAiToolShapes::toChatCompletionsTools([$this->webSearchTool->declaration()])[0];

        return [
            'openai' => $openai,
            'dispatch' => [
                'kind' => GatewayToolCatalog::KIND_NATIVE,
                'serverId' => 0,
                'tool' => WebSearchTool::NAME,
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $clientTools
     *
     * @return array<string, true>
     */
    private function clientFunctionNames(array $clientTools): array
    {
        $names = [];
        foreach ($clientTools as $tool) {
            $name = null;
            if (isset($tool['function']['name']) && is_string($tool['function']['name'])) {
                $name = $tool['function']['name'];
            } elseif (isset($tool['name']) && is_string($tool['name'])) {
                $name = $tool['name'];
            }
            if (is_string($name) && '' !== $name) {
                $names[$name] = true;
            }
        }

        return $names;
    }
}
