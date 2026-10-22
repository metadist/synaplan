<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ApiKeyScope;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class KnowledgeSearchToolTest extends TestCase
{
    public function testDesktopModeOffersTheToolToAPairedKeyWithAFolder(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: 'DESKTOP:personal'));

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, null));
    }

    public function testDesktopModeHidesTheToolFromOtherKeys(): void
    {
        $tool = $this->tool($this->request(paired: false, folder: 'DESKTOP:personal'));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, null));
    }

    public function testAllModeOffersTheToolToOtherKeys(): void
    {
        $tool = $this->tool($this->request(paired: false, folder: 'DESKTOP:personal'));

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_ALL, null));
    }

    public function testOffHidesTheTool(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: 'DESKTOP:personal'));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_OFF, null));
    }

    public function testAPinnedFolderIsRequired(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: null));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_ALL, null));
    }

    public function testAssistantScopesCountAsAPinnedFolder(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: null));
        $profile = new RuntimeProfile(null, '', null, [], [
            ['ownerId' => 3, 'groupKey' => 'DESKTOP:personal'],
        ], [], null, null, []);

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, $profile));
    }

    public function testAHitKeepsTheFileAndChunkIds(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchHelper::class);
        $knowledge->method('searchForTool')->willReturn([
            'hits' => [[
                'file_name' => 'invoice.pdf',
                'file_id' => 12,
                'chunk_id' => 34,
                'score' => 0.81234,
                'chunk_text' => 'Total 40',
            ]],
            'error' => null,
        ]);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        $result = (new KnowledgeSearchTool($knowledge, new RequestStack()))->execute(
            ['query' => 'total', 'limit' => 50],
            $user,
            null,
        );

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('file: invoice.pdf', $result['text']);
        $this->assertStringContainsString('file_id: 12', $result['text']);
        $this->assertStringContainsString('chunk_id: 34', $result['text']);
        $this->assertStringContainsString('Total 40', $result['text']);
        $this->assertStringContainsString('when the passages above are not enough', (new KnowledgeSearchTool($knowledge, new RequestStack()))->declaration()['description']);
    }

    private function tool(Request $request): KnowledgeSearchTool
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new KnowledgeSearchTool($this->createStub(KnowledgeSearchHelper::class), $stack);
    }

    private function request(bool $paired, ?string $folder): Request
    {
        $request = new Request();
        if (null !== $folder) {
            $request->headers->set('x-synaplan-rag-group-key', $folder);
        }
        $key = $this->createStub(ApiKey::class);
        $key->method('getScopes')->willReturn($paired ? [ApiKeyScope::DESKTOP_MESSAGES] : ['messages']);
        $request->attributes->set('api_key', $key);

        return $request;
    }
}
