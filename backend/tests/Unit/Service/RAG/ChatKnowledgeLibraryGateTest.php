<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\RAG;

use App\Repository\LibraryFileNameQuery;
use App\Service\File\LibraryFileMentions;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\RAG\ChatKnowledgeContextLoader;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatKnowledgeLibraryGateTest extends TestCase
{
    private const QUESTION = 'steht in meinen Dokumenten Änderungsauftrag';

    public function testAChatThatNamesALibraryDocumentSearchesIt(): void
    {
        self::assertTrue($this->loader()->shouldSearch(null, self::QUESTION, ['intent' => 'chat'], [], false, 1));
    }

    public function testAChatThatNamesNoDocumentDoesNotSearch(): void
    {
        self::assertFalse($this->loader()->shouldSearch(null, 'Wie wird das Wetter morgen?', ['intent' => 'chat'], [], false, 1));
    }

    public function testAnAssistantKeepsItsOwnScopesAndKnowledgeCanBeTurnedOff(): void
    {
        $emptyScope = [new RagScope(1, '')];

        self::assertFalse($this->loader()->shouldSearch($emptyScope, self::QUESTION, [], [], false, 1));
        self::assertFalse($this->loader()->shouldSearch(null, self::QUESTION, [], ['disable_knowledge' => true], false, 1));
    }

    private function loader(): ChatKnowledgeContextLoader
    {
        $names = $this->createStub(LibraryFileNameQuery::class);
        $names->method('searchableForUser')->willReturn([['id' => 480, 'name' => 'Änderungsauftrag.PDF']]);

        return new ChatKnowledgeContextLoader(
            $this->createStub(VectorSearchService::class),
            $this->createStub(KnowledgeContextFormatter::class),
            new NullLogger(),
            new LibraryFileMentions($names),
        );
    }
}
