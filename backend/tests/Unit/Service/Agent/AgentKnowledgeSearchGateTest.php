<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Service\Agent\AgentKnowledgeSearchGate;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use PHPUnit\Framework\TestCase;

final class AgentKnowledgeSearchGateTest extends TestCase
{
    public function testGreetingDoesNotSearchWhenNothingElseAsked(): void
    {
        $scopes = [new RagScope(4, null)];

        self::assertFalse(AgentKnowledgeSearchGate::shouldSearch($scopes, 'hello', false));
    }

    public function testQuestionSearchesThePersonsOwnFiles(): void
    {
        $scopes = [new RagScope(4, null)];

        self::assertTrue(AgentKnowledgeSearchGate::shouldSearch($scopes, 'Who is the customer on the invoice?', false));
    }

    public function testAlreadySearchingStaysOn(): void
    {
        self::assertTrue(AgentKnowledgeSearchGate::shouldSearch(null, 'hello', true));
    }

    public function testNamedFileIdsCountAsKnowledge(): void
    {
        $scopes = [new RagScope(4, null, [15])];

        self::assertTrue(AgentKnowledgeSearchGate::shouldSearch($scopes, 'Summarize the attached contract', false));
    }

    public function testNonAssistantTurnDoesNotSearch(): void
    {
        self::assertFalse(AgentKnowledgeSearchGate::shouldSearch(null, 'Who is the customer on the invoice?', false));
    }
}
