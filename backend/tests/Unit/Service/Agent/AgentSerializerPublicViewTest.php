<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Service\Agent\AgentSerializer;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use PHPUnit\Framework\TestCase;

final class AgentSerializerPublicViewTest extends TestCase
{
    public function testPublicViewIncludesReaderModelsAndDropsDraft(): void
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('getId')->willReturn(9);
        $agent->method('getSlug')->willReturn('review');
        $agent->method('getName')->willReturn('Review');
        $agent->method('getDescription')->willReturn('Reads contracts');
        $agent->method('getIcon')->willReturn('');
        $agent->method('getStatus')->willReturn(Agent::STATUS_PUBLISHED);
        $agent->method('getPromptId')->willReturn(3);
        $agent->method('getParentId')->willReturn(null);
        $agent->method('getSource')->willReturn(Agent::SOURCE_MANUAL);
        $agent->method('isRoutable')->willReturn(false);
        $agent->method('getPublishedVersionId')->willReturn(4);
        $agent->method('hasPublishedVersion')->willReturn(true);
        $agent->method('getDraft')->willReturn([
            'models' => ['chat' => 'secret:draft:chat'],
            'tools' => ['token' => 'nope'],
        ]);
        $agent->method('getCreated')->willReturn(1);
        $agent->method('getUpdated')->willReturn(2);

        $view = (new AgentSerializer(new AgentDefinitionValidator()))->publicView($agent, [
            'models' => [
                'chat' => 'ollama:llama3.2:chat',
                'vision' => null,
                'vectorize' => 'ollama:bge-m3:vectorize',
            ],
        ]);

        $this->assertSame(9, $view['id']);
        $this->assertArrayNotHasKey('draft', $view);
        $this->assertSame([
            'chat' => 'ollama:llama3.2:chat',
            'vision' => null,
            'vectorize' => 'ollama:bge-m3:vectorize',
        ], $view['models']);
    }

    public function testFullFillsKeysMissingFromStoredDraft(): void
    {
        $agent = $this->createStub(Agent::class);
        $agent->method('getId')->willReturn(2);
        $agent->method('getSlug')->willReturn('new-assistant');
        $agent->method('getName')->willReturn('TestAssistant Cat');
        $agent->method('getDescription')->willReturn('It creates a cat image every day');
        $agent->method('getIcon')->willReturn('');
        $agent->method('getStatus')->willReturn(Agent::STATUS_PUBLISHED);
        $agent->method('getPromptId')->willReturn(21);
        $agent->method('getParentId')->willReturn(null);
        $agent->method('getSource')->willReturn(Agent::SOURCE_MANUAL);
        $agent->method('isRoutable')->willReturn(false);
        $agent->method('getPublishedVersionId')->willReturn(3);
        $agent->method('getDraft')->willReturn([
            'schema' => 'agent.v1',
            'knowledge' => [
                'ownFolder' => true,
                'folders' => [],
                'ragLimit' => 8,
                'ragMinScore' => 0.6,
            ],
        ]);
        $agent->method('getCreated')->willReturn(1);
        $agent->method('getUpdated')->willReturn(2);

        $payload = (new AgentSerializer(new AgentDefinitionValidator()))->full($agent);

        self::assertFalse($payload['draft']['knowledge']['includeUserFiles']);
        self::assertSame([], $payload['draft']['knowledge']['fileIds']);
    }
}
