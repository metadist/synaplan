<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\AI\Messages\Tools\ReadDocumentTool;
use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ApiKeyScope;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class KnowledgeSearchToolTest extends TestCase
{
    public function testDesktopModeOffersTheToolToAPairedKeyWithAFolder(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: 'DESKTOP:personal'));

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, null, 3));
    }

    public function testDesktopModeHidesTheToolFromOtherKeys(): void
    {
        $tool = $this->tool($this->request(paired: false, folder: 'DESKTOP:personal'));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, null, 3));
    }

    public function testAllModeOffersTheToolToOtherKeys(): void
    {
        $tool = $this->tool($this->request(paired: false, folder: 'DESKTOP:personal'));

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_ALL, null, 3));
    }

    public function testOffHidesTheTool(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: 'DESKTOP:personal'));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_OFF, null, 3));
    }

    public function testAPinnedFolderIsRequired(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: null));

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_ALL, null, 3));
    }

    public function testAssistantScopesCountAsAPinnedFolder(): void
    {
        $tool = $this->tool($this->request(paired: true, folder: null));
        $profile = new RuntimeProfile(null, '', null, [], [
            ['ownerId' => 3, 'groupKey' => 'DESKTOP:personal'],
        ], [], null, null, []);

        $this->assertTrue($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, $profile, 3));
    }

    public function testAPinnedFolderTheCallerCannotUseIsNotOffered(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchHelper::class);
        $knowledge->expects($this->once())->method('refusal')
            ->with(3, 'shared:9:contracts', null)
            ->willReturn(KnowledgeSearchHelper::REFUSED);
        $tool = $this->tool($this->request(paired: true, folder: 'shared:9:contracts'), $knowledge);

        $this->assertFalse($tool->isOffered(MessagesGatewayConfig::KNOWLEDGE_DESKTOP, null, 3));
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

        $tool = new KnowledgeSearchTool($knowledge, new RequestStack(), new NullLogger());
        $result = $tool->execute(['query' => 'total', 'limit' => 50], $this->user(), null);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('file: invoice.pdf', $result['text']);
        $this->assertStringContainsString('file_id: 12', $result['text']);
        $this->assertStringContainsString('chunk_id: 34', $result['text']);
        $this->assertStringContainsString('Total 40', $result['text']);
        $this->assertStringContainsString('when the passages above are not enough', $tool->declaration()['description']);
    }

    public function testASearchFailureDoesNotLeakTheInternalError(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchHelper::class);
        $knowledge->method('searchForTool')->willThrowException(new \RuntimeException('SQLSTATE[HY000] db-host-7 refused'));

        $result = (new KnowledgeSearchTool($knowledge, new RequestStack(), new NullLogger()))
            ->execute(['query' => 'total'], $this->user(), null);

        $this->assertTrue($result['isError']);
        $this->assertSame(KnowledgeSearchTool::SEARCH_FAILED, $result['text']);
    }

    public function testAReadFailureDoesNotLeakTheInternalError(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchHelper::class);
        $knowledge->method('readForTool')->willThrowException(new \RuntimeException('qdrant:6333 timed out'));

        $result = (new ReadDocumentTool($knowledge, new RequestStack(), new NullLogger()))
            ->execute(['file_id' => 12], $this->user(), null);

        $this->assertTrue($result['isError']);
        $this->assertSame(ReadDocumentTool::READ_FAILED, $result['text']);
    }

    public function testANumericChunkIdIsPassedOn(): void
    {
        $knowledge = $this->createMock(KnowledgeSearchHelper::class);
        $knowledge->expects($this->once())->method('readForTool')
            ->with(3, 12, '34', null, null)
            ->willReturn(['text' => 'ok', 'isError' => false]);

        (new ReadDocumentTool($knowledge, new RequestStack(), new NullLogger()))
            ->execute(['file_id' => 12, 'around' => 34], $this->user(), null);
    }

    private function tool(Request $request, ?KnowledgeSearchHelper $knowledge = null): KnowledgeSearchTool
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new KnowledgeSearchTool($knowledge ?? $this->createStub(KnowledgeSearchHelper::class), $stack, new NullLogger());
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(3);

        return $user;
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
