<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\StructuredOutput\Schema\ResearchFinishSchema;
use App\AI\StructuredOutput\Schema\ResearchJsonSchema;
use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolDefinition;
use App\Service\Research\Run\Stage\StageContext;

/**
 * The tools the lead may call. Each one enforces the budget itself. Only
 * what can run is declared: asking the person waits until a run can wait
 * without holding a worker, and file search waits for the knowledge source
 * (RS9). A model that calls either anyway is refused.
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
        $point = ['type' => 'string', 'description' => 'Id of the plan point, for example p1.'];
        $source = ['type' => 'string', 'description' => 'Source id from a search result, for example s3.'];

        return [
            new ToolDefinition('search', 'Search the web for one plan point. Returns short results with source ids, never the page.', ResearchJsonSchema::object([
                'point' => $point,
                'query' => ['type' => 'string', 'maxLength' => 200, 'description' => 'A short search query. Never repeat an earlier one.'],
                'language' => ['type' => 'string', 'description' => 'Two-letter language code of the query, for example en.'],
                'scope' => ResearchJsonSchema::enum(['web']),
            ], ['point', 'query', 'language', 'scope'])),
            new ToolDefinition('read_sources', 'Read found sources and return evidence cards for one plan point.', ResearchJsonSchema::object([
                'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => $source],
                'point' => $point,
            ], ['ids', 'point'])),
            new ToolDefinition('reread', 'Read one source you already read again, to answer a sharper question. Returns evidence cards.', ResearchJsonSchema::object([
                'source' => $source,
                'question' => ['type' => 'string', 'maxLength' => 300],
            ], ['source', 'question'])),
            new ToolDefinition('finish', 'End the research and give every plan point a status. This is the only way to end; never answer in text.', ResearchFinishSchema::schema()),
        ];
    }

    public function execute(ToolCall $call, StageContext $ctx): LeadToolOutcome
    {
        return match ($call->name) {
            'search' => new LeadToolOutcome($this->search->run($call, $ctx)),
            'read_sources' => $this->read->sources($call, $ctx),
            'reread' => $this->read->again($call, $ctx),
            'ask_user', 'finish' => new LeadToolOutcome(LeadToolReply::error('not_allowed', $ctx->state->budget)),
            default => new LeadToolOutcome(LeadToolReply::error('unknown_tool', $ctx->state->budget)),
        };
    }
}
