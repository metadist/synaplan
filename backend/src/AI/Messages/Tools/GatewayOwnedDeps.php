<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;

/**
 * Services a gateway-owned tool call needs. Kept as one object so
 * {@see GatewayOwnedCall} stays inside the constructor-size limit.
 */
final readonly class GatewayOwnedDeps
{
    public function __construct(
        public McpClient $mcpClient,
        public McpServerConfigRepository $servers,
        public NativeToolRegistry $nativeTools,
        public AnalyzeImageTool $analyzeImageTool,
        public ?ToolExecutionGate $executionGate = null,
        public ?ToolRegistry $toolRegistry = null,
        public ?ToolsConfig $toolsConfig = null,
        public ?CodeExecutionTool $codeExecutionTool = null,
        public ?HttpToolExecutor $httpExecutor = null,
        public ?CustomToolRepository $customTools = null,
    ) {
    }
}
