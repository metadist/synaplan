<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\StructuredOutput\Schema\ResearchFinishSchema;
use App\AI\StructuredOutput\Schema\ResearchJsonSchema;
use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolDefinition;
use App\Service\Research\Run\Stage\StageContext;

/**
 * The five tools the lead may call. Each one enforces the budget itself.
 * Asking the person is declared, and refused, until a run can wait for
 * an answer without holding a worker.
 */
final readonly class LeadToolbox
{
    public function __construct(
        private LeadSearch $search,
        private LeadRead $read,
    ) {
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        return [
            new ToolDefinition('search', 'Search the web for one plan point. Returns short results, never the page.', ResearchJsonSchema::object([
                'point' => ResearchJsonSchema::string(),
                'query' => ['type' => 'string', 'maxLength' => 200],
                'language' => ResearchJsonSchema::string(),
                'scope' => ResearchJsonSchema::enum(['web', 'files', 'both']),
            ], ['point', 'query', 'language', 'scope'])),
            new ToolDefinition('read_sources', 'Read found sources and return evidence cards for one plan point.', ResearchJsonSchema::object([
                'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ResearchJsonSchema::string()],
                'point' => ResearchJsonSchema::string(),
            ], ['ids', 'point'])),
            new ToolDefinition('reread', 'Read one source again to answer a sharper question. Returns evidence cards.', ResearchJsonSchema::object([
                'source' => ResearchJsonSchema::string(),
                'question' => ['type' => 'string', 'maxLength' => 300],
            ], ['source', 'question'])),
            new ToolDefinition('ask_user', 'Ask the person a question only they can answer.', ResearchJsonSchema::object([
                'question' => ['type' => 'string', 'maxLength' => 300],
                'options' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ResearchJsonSchema::string()],
            ], ['question', 'options'])),
            new ToolDefinition('finish', 'End the research and give every plan point a status.', ResearchFinishSchema::schema()),
        ];
    }

    public function execute(ToolCall $call, StageContext $ctx): LeadToolOutcome
    {
        return match ($call->name) {
            'search' => new LeadToolOutcome($this->search->run($call, $ctx)),
            'read_sources' => $this->read->sources($call, $ctx),
            'reread' => $this->read->again($call, $ctx),
            'ask_user', 'finish' => new LeadToolOutcome(LeadToolReply::error('not_allowed', $ctx->state->budget)),
            default => new LeadToolOutcome(LeadToolReply::error('not_allowed', $ctx->state->budget)),
        };
    }
}
