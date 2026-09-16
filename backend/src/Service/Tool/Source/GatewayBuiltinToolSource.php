<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final readonly class GatewayBuiltinToolSource implements ToolSourceInterface
{
    public function __construct(
        private WebSearchTool $webSearchTool,
        private AnalyzeImageTool $analyzeImageTool,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Builtin;
    }

    public function describe(int $userId, array $context = []): array
    {
        $tools = [];
        $web = $this->webSearchTool->declaration();
        $tools[] = new ToolDescriptor(
            name: WebSearchTool::NAME,
            title: 'Web search',
            description: $web['description'],
            inputSchema: $web['input_schema'],
            sideEffect: SideEffect::Read,
            source: ToolSource::Builtin,
            ownerId: 0,
            meta: ['available' => $this->webSearchTool->isAvailable()],
        );

        $vision = $this->analyzeImageTool->declaration();
        $tools[] = new ToolDescriptor(
            name: AnalyzeImageTool::NAME,
            title: 'Analyze image',
            description: $vision['description'],
            inputSchema: $vision['input_schema'],
            sideEffect: SideEffect::Read,
            source: ToolSource::Builtin,
            ownerId: 0,
            meta: ['available' => $this->analyzeImageTool->isAvailable($userId > 0 ? $userId : null)],
        );

        return $tools;
    }
}
