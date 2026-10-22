<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\AI\Messages\Tools\ReadDocumentTool;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

/**
 * Every native tool the Messages gateway can dispatch must be listed here:
 * with TOOLS.REGISTRY_ENABLED on, the gateway refuses a name the registry
 * does not know.
 */
final readonly class GatewayBuiltinToolSource implements ToolSourceInterface
{
    public function __construct(
        private WebSearchTool $webSearchTool,
        private AnalyzeImageTool $analyzeImageTool,
        private WebFetchTool $webFetchTool,
        private KnowledgeSearchTool $knowledgeSearchTool,
        private ReadDocumentTool $readDocumentTool,
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

        $tools[] = $this->gatewayOnly($this->webFetchTool->declaration(), 'Read a web page');
        $tools[] = $this->gatewayOnly($this->knowledgeSearchTool->declaration(), 'Search the knowledge folder');
        $tools[] = $this->gatewayOnly($this->readDocumentTool->declaration(), 'Read a file in the folder');

        return $tools;
    }

    /**
     * @param array{name: string, description: string, input_schema: array<string, mixed>} $declaration
     */
    private function gatewayOnly(array $declaration, string $title): ToolDescriptor
    {
        return new ToolDescriptor(
            name: $declaration['name'],
            title: $title,
            description: $declaration['description'],
            inputSchema: $declaration['input_schema'],
            sideEffect: SideEffect::Read,
            source: ToolSource::Builtin,
            ownerId: 0,
            meta: ['available' => true],
        );
    }
}
