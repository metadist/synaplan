<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\Feedback\FeedbackConstants;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Vector DB tab: the Qdrant URL (environment) and the search thresholds
 * (BCONFIG group QDRANT_SEARCH, no restart).
 */
#[AsTaggedItem(priority: 10)]
final readonly class VectorDbTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'vectordb';
    }

    public function label(): string
    {
        return 'Vector DB';
    }

    public function sections(): array
    {
        return [
            'qdrant' => $this->qdrant(),
            'qdrant_search' => $this->qdrantSearch(),
        ];
    }

    private function qdrant(): ConfigSection
    {
        return new ConfigSection('Qdrant', [
            'QDRANT_URL' => new FieldDefinition(
                type: 'url',
                description: 'Qdrant REST API URL',
                default: 'http://qdrant:6333',
            ),
        ]);
    }

    private function qdrantSearch(): ConfigSection
    {
        return new ConfigSection('Search Thresholds', [
            'MIN_CHAT_FEEDBACK_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for feedback in chat context (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE,
                row: new DatabaseRow(),
            ),
            'MIN_CHAT_MEMORY_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for memories in chat context (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_CHAT_MEMORY_SCORE,
                row: new DatabaseRow(),
            ),
            'MIN_CONTRADICTION_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for contradiction detection (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_CONTRADICTION_SCORE,
                row: new DatabaseRow(),
            ),
            'MIN_RESEARCH_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for KB/document research (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_RESEARCH_SCORE,
                row: new DatabaseRow(),
            ),
            'MIN_MEMORY_RESEARCH_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for memory research (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_MEMORY_RESEARCH_SCORE,
                row: new DatabaseRow(),
            ),
            'MIN_EXTRACTION_SCORE' => new FieldDefinition(
                type: 'number',
                description: 'Min score for memory extraction context (0.0–1.0)',
                default: (string) FeedbackConstants::MIN_EXTRACTION_SCORE,
                row: new DatabaseRow(),
            ),
            'LIMIT_PER_NAMESPACE' => new FieldDefinition(
                type: 'number',
                description: 'Max results per Qdrant namespace',
                default: (string) FeedbackConstants::LIMIT_PER_NAMESPACE,
                row: new DatabaseRow(),
            ),
            'MAX_CHAT_MEMORIES' => new FieldDefinition(
                type: 'number',
                description: 'Max memories loaded into chat context',
                default: '5',
                row: new DatabaseRow(),
            ),
        ]);
    }
}
