<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Entity\Agent;
use App\Service\Agent\AgentSerializer;
use PHPUnit\Framework\TestCase;

final class AgentSerializerPublicViewTest extends TestCase
{
    public function testPublicViewIncludesReaderModelsAndDropsDraft(): void
    {
        $agent = $this->createMock(Agent::class);
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
        $agent->method('getDraft')->willReturn([
            'models' => ['chat' => 'secret:draft:chat'],
            'tools' => ['token' => 'nope'],
        ]);
        $agent->method('getCreated')->willReturn(1);
        $agent->method('getUpdated')->willReturn(2);

        $view = (new AgentSerializer())->publicView($agent, [
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
}
