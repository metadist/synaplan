<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\SearchResultRepository;
use App\Service\Calendar\CalendarEventService;
use App\Service\File\FileStorageService;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\FileAnalysisHandler;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\CalendarEventRunner;
use App\Service\Multitask\Execution\Runner\ChatRunner;
use App\Service\Multitask\Execution\Runner\ComposeReplyRunner;
use App\Service\Multitask\Execution\Runner\DocumentGenerationRunner;
use App\Service\Multitask\Execution\Runner\ExtractTextRunner;
use App\Service\Multitask\Execution\Runner\FileAnalysisRunner;
use App\Service\Multitask\Execution\Runner\MediaGenerationRunner;
use App\Service\Multitask\Execution\Runner\Text2SoundRunner;
use App\Service\Multitask\Execution\Runner\WebSearchRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\RAG\VectorSearchService;
use App\Service\Search\BraveSearchService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RunnersTest extends TestCase
{
    private function message(string $text = '', string $fileText = ''): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getFileText')->willReturn($fileText);
        $m->method('getLanguage')->willReturn('en');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    private function messageWithFile(string $text = ''): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getFileText')->willReturn('');
        $m->method('getLanguage')->willReturn('en');
        $m->method('getFile')->willReturn(1);
        $m->method('getFilePath')->willReturn('01/000/00001/2026/06/cat.png');
        $m->method('getFileType')->willReturn('png');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    private function context(Message $message): NodeContext
    {
        return new NodeContext($message, [], 1, ['language' => 'en']);
    }

    public function testExtractTextReadsResolvedFileText(): void
    {
        $ctx = $this->context($this->message());
        $node = new TaskNode('n1', Capability::ExtractText, [], ['files' => '$message.files']);
        // Simulate resolved file with extracted text via a direct input literal.
        $node = new TaskNode('n1', Capability::ExtractText, [], ['files' => [['path' => 'a.pdf', 'type' => 'pdf', 'text' => 'HELLO DOC']]]);

        $result = (new ExtractTextRunner())->run($node, $ctx);

        self::assertTrue($result->isSuccessful());
        self::assertSame('HELLO DOC', $result->text);
    }

    public function testExtractTextFallsBackToMessageFileText(): void
    {
        $ctx = $this->context($this->message(fileText: 'LEGACY TEXT'));
        $node = new TaskNode('n1', Capability::ExtractText);

        $result = (new ExtractTextRunner())->run($node, $ctx);

        self::assertSame('LEGACY TEXT', $result->text);
    }

    public function testExtractTextFailsWhenNoText(): void
    {
        $result = (new ExtractTextRunner())->run(new TaskNode('n1', Capability::ExtractText), $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testSummarizeCallsModelAndReturnsContent(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(76);
        $modelConfig->method('getProviderForModel')->willReturn('groq');
        $modelConfig->method('getModelName')->willReturn('gpt-oss-120b');

        $captured = null;
        // chatStream(messages, callback, userId, options) streams chunks then returns metadata.
        $aiFacade->method('chatStream')->willReturnCallback(function (array $messages, callable $cb) use (&$captured): array {
            $captured = $messages;
            $cb('THE ');
            $cb('SUMMARY');

            return ['provider' => 'groq', 'model' => 'gpt-oss-120b'];
        });

        $runner = new ChatRunner($aiFacade, $modelConfig, $this->createMock(VectorSearchService::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n2', Capability::Summarize, ['n1'], ['text' => 'long input text']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertSame('THE SUMMARY', $result->text);
        // The upstream text reached the model as the user turn.
        self::assertSame('long input text', $captured[1]['content']);
    }

    /**
     * Regression for issue #1067: structured reasoning chunks (chain-of-thought
     * from thinking models like gpt-oss) must neither be streamed to the task
     * card nor end up in the node output — only the visible answer text counts.
     */
    public function testChatRunnerDropsReasoningChunks(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfig = $this->createMock(ModelConfigService::class);

        $aiFacade->method('chatStream')->willReturnCallback(function (array $messages, callable $cb): array {
            $cb(['type' => 'reasoning', 'content' => 'The instruction: answer in German, no preamble. ']);
            $cb(['type' => 'content', 'content' => 'Heute wird es ']);
            $cb(['type' => 'content', 'content' => 'sonnig.']);
            $cb(['type' => 'finish', 'finish_reason' => 'stop']);

            return [];
        });

        $runner = new ChatRunner($aiFacade, $modelConfig, $this->createMock(VectorSearchService::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n2', Capability::Chat, [], ['text' => 'wie wird das Wetter heute?']);

        $context = $this->context($this->message('wie wird das Wetter heute?'));
        $streamed = '';
        $context->setChunkSink(static function (string $nodeId, string $chunk) use (&$streamed): void {
            $streamed .= $chunk;
        });
        $context->beginNode('n2');

        $result = $runner->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertSame('Heute wird es sonnig.', $result->text);
        self::assertSame('Heute wird es sonnig.', $streamed);
        self::assertStringNotContainsString('The instruction', (string) $result->text);
    }

    public function testChatRunnerFailsOnEmptyInput(): void
    {
        $runner = new ChatRunner($this->createMock(AiFacade::class), $this->createMock(ModelConfigService::class), $this->createMock(VectorSearchService::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::Chat, [], ['text' => '']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testRagQueryRetrievesKnowledgeBaseContext(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $modelConfig = $this->createMock(ModelConfigService::class);

        $vectorSearch = $this->createMock(VectorSearchService::class);
        $vectorSearch->expects(self::once())
            ->method('semanticSearch')
            ->with('what does our contract say about notice periods?', 1, 'GROUP:legal')
            ->willReturn([
                ['chunk_text' => 'Notice period is 3 months.'],
                ['chunk_text' => 'Termination must be in writing.'],
            ]);

        $capturedSystem = null;
        $aiFacade->method('chatStream')->willReturnCallback(function (array $messages, callable $cb) use (&$capturedSystem): array {
            $capturedSystem = $messages[0]['content'];
            $cb('3 months, in writing.');

            return [];
        });

        $runner = new ChatRunner($aiFacade, $modelConfig, $vectorSearch, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::RagQuery, [], ['text' => 'what does our contract say about notice periods?']);

        $context = new NodeContext(
            $this->message('what does our contract say about notice periods?'),
            [],
            1,
            ['language' => 'en', 'rag_group_key' => 'GROUP:legal'],
        );
        $result = $runner->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('Knowledge Base Context', (string) $capturedSystem);
        self::assertStringContainsString('Notice period is 3 months.', (string) $capturedSystem);
        self::assertSame(2, $result->metadata['rag_chunks'] ?? null);
    }

    public function testRagQueryDegradesToPlainAnswerWhenRetrievalFails(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback(function (array $messages, callable $cb): array {
            $cb('plain answer');

            return [];
        });

        $vectorSearch = $this->createMock(VectorSearchService::class);
        $vectorSearch->method('semanticSearch')->willThrowException(new \RuntimeException('qdrant down'));

        $runner = new ChatRunner($aiFacade, $this->createMock(ModelConfigService::class), $vectorSearch, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::RagQuery, [], ['text' => 'question']);

        $result = $runner->run($node, $this->context($this->message('question')));

        self::assertTrue($result->isSuccessful());
        self::assertSame('plain answer', $result->text);
        self::assertSame(0, $result->metadata['rag_chunks'] ?? null);
    }

    public function testChatRunnerIsolatesModelFailure(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chatStream')->willThrowException(new \RuntimeException('groq 500'));
        $modelConfig = $this->createMock(ModelConfigService::class);

        $runner = new ChatRunner($aiFacade, $modelConfig, $this->createMock(VectorSearchService::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n2', Capability::Summarize, [], ['text' => 'input']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('failed', (string) $result->error);
    }

    public function testText2SoundProducesAudioFile(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('synthesize')->willReturn([
            'relativePath' => '1/000/2026/06/tts_x.mp3',
            'provider' => 'piper',
            'model' => 'piper-multi',
        ]);

        $runner = new Text2SoundRunner($aiFacade, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n3', Capability::Text2Sound, ['n2'], ['text' => 'read this aloud'], ['format' => 'mp3']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('audio', $result->files[0]['type']);
        self::assertSame('/api/v1/files/uploads/1/000/2026/06/tts_x.mp3', $result->files[0]['path']);
        self::assertSame('1/000/2026/06/tts_x.mp3', $result->files[0]['local_path']);
    }

    public function testText2SoundFailsWhenNoText(): void
    {
        $runner = new Text2SoundRunner($this->createMock(AiFacade::class), $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n3', Capability::Text2Sound, [], ['text' => '']);

        $result = $runner->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
    }

    public function testMediaGenerationRunnerProducesImageFile(): void
    {
        $handler = $this->createMock(MediaGenerationHandler::class);
        $captured = null;
        $handler->method('handle')->willReturnCallback(function ($msg, $thread, $classification) use (&$captured): array {
            $captured = $classification;

            return [
                'content' => 'Generated image: a dog',
                'metadata' => [
                    'file' => ['path' => '/api/v1/files/uploads/1/000/dog.png', 'type' => 'image'],
                    'local_path' => '1/000/dog.png',
                ],
            ];
        });

        $runner = new MediaGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::ImageGeneration, [], ['prompt' => 'a happy dog']);

        $result = $runner->run($node, $this->context($this->message('a happy dog')));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('image', $result->files[0]['type']);
        self::assertSame('1/000/dog.png', $result->files[0]['local_path']);
        self::assertSame('tools:pic', $captured['topic']);
        self::assertSame('image', $captured['media_type']);
    }

    public function testMediaGenerationRunnerFailsWhenNoFile(): void
    {
        $handler = $this->createMock(MediaGenerationHandler::class);
        $handler->method('handle')->willReturn(['metadata' => ['error' => 'provider down']]);

        $runner = new MediaGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::ImageGeneration, [], ['prompt' => 'a dog']);

        $result = $runner->run($node, $this->context($this->message('a dog')));

        self::assertFalse($result->isSuccessful());
    }

    /**
     * Regression: the synthetic message handed to MediaGenerationHandler must
     * carry the inbound message's attachments — the handler detects pic2pic
     * (image edit with a reference image) purely from the files on the message
     * it receives. Dropping them silently degraded "edit this image" to a
     * plain text2pic generation.
     */
    public function testMediaGenerationRunnerForwardsAttachmentsForPic2Pic(): void
    {
        $handler = $this->createMock(MediaGenerationHandler::class);
        $seenMessage = null;
        $handler->method('handle')->willReturnCallback(function (Message $msg) use (&$seenMessage): array {
            $seenMessage = $msg;

            return [
                'content' => 'edited!',
                'metadata' => [
                    'file' => ['path' => '/api/v1/files/uploads/1/000/cat-hat.png', 'type' => 'image'],
                    'local_path' => '1/000/cat-hat.png',
                ],
            ];
        });

        $runner = new MediaGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::ImageGeneration, [], ['prompt' => 'put a hat on this cat']);

        $result = $runner->run($node, $this->context($this->messageWithFile('put a hat on this cat')));

        self::assertTrue($result->isSuccessful());
        self::assertInstanceOf(Message::class, $seenMessage);
        self::assertSame(1, $seenMessage->getFile());
        self::assertSame('01/000/00001/2026/06/cat.png', $seenMessage->getFilePath());
        self::assertSame('png', $seenMessage->getFileType());
    }

    private function calendarRunner(): CalendarEventRunner
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => '1/000/2026/06/meeting.ics',
            'size' => 123,
            'mime' => 'text/calendar',
            'error' => null,
        ]);

        return new CalendarEventRunner(new CalendarEventService(), $storage, $this->createMock(LoggerInterface::class));
    }

    /**
     * Regression: the planner routinely emits the event fields under `inputs`
     * (not `params`) — the runner must still build the invite instead of
     * failing with "missing start time".
     */
    public function testCalendarEventReadsFieldsFromInputs(): void
    {
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [
            'title' => 'Meeting with Sanam',
            'start' => '2026-06-10T09:00:00',
            'timezone' => 'UTC',
            'duration_minutes' => 60,
            'attendees' => ['Sanam'],
        ]);

        $result = $this->calendarRunner()->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('1/000/2026/06/meeting.ics', $result->files[0]['local_path']);
        self::assertSame('Meeting with Sanam', $result->metadata['calendar_event']['title'] ?? null);
    }

    public function testCalendarEventReadsFieldsFromParams(): void
    {
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [], [
            'title' => 'Sync',
            'start' => '2026-06-10T15:00:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $result = $this->calendarRunner()->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
    }

    public function testCalendarEventParamsWinOverInputs(): void
    {
        $node = new TaskNode('n1', Capability::CalendarEvent, [], [
            'title' => 'From inputs',
        ], [
            'title' => 'From params',
            'start' => '2026-06-10T09:00:00',
            'timezone' => 'UTC',
        ]);

        $result = $this->calendarRunner()->run($node, $this->context($this->message()));

        self::assertTrue($result->isSuccessful());
        self::assertSame('From params', $result->metadata['calendar_event']['title'] ?? null);
    }

    public function testCalendarEventFailsWhenStartMissingEverywhere(): void
    {
        $node = new TaskNode('n1', Capability::CalendarEvent, [], ['title' => 'No time']);

        $result = $this->calendarRunner()->run($node, $this->context($this->message()));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('missing start time', (string) $result->error);
    }

    public function testDocumentGenerationLiftsGeneratedFileFromHandler(): void
    {
        $handler = $this->createMock(ChatHandler::class);
        $captured = null;
        $handler->method('handle')->willReturnCallback(function ($msg, $thread, $classification) use (&$captured): array {
            $captured = $classification;

            return [
                'content' => '__FILE_GENERATED__:WM_Spielplan.docx',
                'metadata' => [
                    'generated_file' => [
                        'id' => 9,
                        'filename' => 'WM_Spielplan.docx',
                        'path' => '01/000/00001/2026/06/WM_Spielplan_1.docx',
                        'size' => 7400,
                        'type' => 'docx',
                    ],
                ],
            ];
        });

        $runner = new DocumentGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::DocumentGeneration, [], ['text' => 'Create a docx with a table']);

        $result = $runner->run($node, $this->context($this->message('Create a docx with a table')));

        self::assertTrue($result->isSuccessful());
        self::assertCount(1, $result->files);
        self::assertSame('document', $result->files[0]['type']);
        self::assertSame('/api/v1/files/uploads/01/000/00001/2026/06/WM_Spielplan_1.docx', $result->files[0]['path']);
        self::assertSame('01/000/00001/2026/06/WM_Spielplan_1.docx', $result->files[0]['local_path']);
        // ChatHandler must be steered to the officemaker file-generation prompt.
        self::assertSame('officemaker', $captured['topic']);
        self::assertSame('document_generation', $captured['intent']);
    }

    public function testDocumentGenerationFailsWhenNoFileProduced(): void
    {
        $handler = $this->createMock(ChatHandler::class);
        $handler->method('handle')->willReturn(['content' => '__FILE_GENERATION_FAILED__', 'metadata' => ['error' => 'llm error']]);

        $runner = new DocumentGenerationRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::DocumentGeneration, [], ['text' => 'make a doc']);

        $result = $runner->run($node, $this->context($this->message('make a doc')));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no file', (string) $result->error);
    }

    public function testWebSearchReturnsFormattedResults(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->method('search')->willReturn(['query' => 'mars news', 'results' => [['title' => 'X']], 'query_metadata' => ['total' => 1]]);
        $brave->method('formatResultsForAI')->willReturn('Web Search Results for: "mars news"');

        $queryGen = $this->createMock(SearchQueryGenerator::class);
        $queryGen->method('generate')->willReturn('mars news');

        $runner = new WebSearchRunner($queryGen, $brave, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => 'latest mars news']);

        $result = $runner->run($node, $this->context($this->message('latest mars news')));

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('Web Search Results', (string) $result->text);
        self::assertTrue($result->metadata['web_search'] ?? false);
        self::assertSame('mars news', $result->metadata['query'] ?? null);
    }

    public function testWebSearchFailsWhenDisabled(): void
    {
        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(false);

        $runner = new WebSearchRunner($this->createMock(SearchQueryGenerator::class), $brave, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => 'anything']);

        $result = $runner->run($node, $this->context($this->message('anything')));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('not configured', (string) $result->error);
    }

    public function testWebSearchReusesPrefetchedResultsForWholeMessageQuery(): void
    {
        $preFetched = ['query' => 'mars news', 'results' => [['title' => 'X']]];

        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->expects(self::never())->method('search');
        $brave->method('formatResultsForAI')->with($preFetched)->willReturn('Web Search Results for: "mars news"');

        $queryGen = $this->createMock(SearchQueryGenerator::class);
        $queryGen->expects(self::never())->method('generate');

        $runner = new WebSearchRunner($queryGen, $brave, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => '$message.text']);

        $context = new NodeContext(
            $this->message('latest mars news'),
            [],
            1,
            ['language' => 'en'],
            ['search_results' => $preFetched],
        );
        $result = $runner->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->metadata['reused_prefetched'] ?? false);
        self::assertSame($preFetched, $result->metadata['search_results'] ?? null);
    }

    public function testWebSearchRunsFreshForPlannerNarrowedQuery(): void
    {
        $preFetched = ['query' => 'mars news', 'results' => [['title' => 'X']]];

        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->expects(self::once())->method('search')->willReturn(['query' => 'rover landing', 'results' => [['title' => 'Y']]]);
        $brave->method('formatResultsForAI')->willReturn('Web Search Results for: "rover landing"');

        $queryGen = $this->createMock(SearchQueryGenerator::class);
        $queryGen->method('generate')->willReturn('rover landing');

        $runner = new WebSearchRunner($queryGen, $brave, $this->createMock(LoggerInterface::class));
        // Planner narrowed this node to a sub-aspect of the request.
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => 'only the rover landing part']);

        $context = new NodeContext(
            $this->message('rover landing news plus weather forecast'),
            [],
            1,
            ['language' => 'en'],
            ['search_results' => $preFetched],
        );
        $result = $runner->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertArrayNotHasKey('reused_prefetched', $result->metadata);
    }

    public function testWebSearchPersistsFreshResultsViaRepository(): void
    {
        $rawResults = ['query' => 'AI breakthrough', 'results' => [['title' => 'X', 'url' => 'https://x.com']]];

        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->method('search')->willReturn($rawResults);
        $brave->method('formatResultsForAI')->willReturn('formatted');

        $queryGen = $this->createMock(SearchQueryGenerator::class);
        $queryGen->method('generate')->willReturn('AI breakthrough');

        $repo = $this->createMock(SearchResultRepository::class);
        $repo->expects(self::once())->method('saveSearchResults');

        $runner = new WebSearchRunner($queryGen, $brave, $this->createMock(LoggerInterface::class), $repo);
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => 'AI breakthrough']);

        $result = $runner->run($node, $this->context($this->message('AI breakthrough')));

        self::assertTrue($result->isSuccessful());
    }

    public function testWebSearchDoesNotPersistWhenReusing(): void
    {
        $preFetched = ['query' => 'AI breakthrough', 'results' => [['title' => 'X']]];

        $brave = $this->createMock(BraveSearchService::class);
        $brave->method('isEnabled')->willReturn(true);
        $brave->expects(self::never())->method('search');
        $brave->method('formatResultsForAI')->willReturn('formatted');

        $queryGen = $this->createMock(SearchQueryGenerator::class);
        $queryGen->expects(self::never())->method('generate');

        $repo = $this->createMock(SearchResultRepository::class);
        $repo->expects(self::never())->method('saveSearchResults');

        $runner = new WebSearchRunner($queryGen, $brave, $this->createMock(LoggerInterface::class), $repo);
        $node = new TaskNode('n1', Capability::WebSearch, [], ['query' => '$message.text']);

        $context = new NodeContext(
            $this->message('AI breakthrough'),
            [],
            1,
            ['language' => 'en'],
            ['search_results' => $preFetched],
        );
        $result = $runner->run($node, $context);

        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->metadata['reused_prefetched'] ?? false);
    }

    public function testFileAnalysisAnswersAboutAttachment(): void
    {
        $handler = $this->createMock(FileAnalysisHandler::class);
        $captured = null;
        $handler->method('handle')->willReturnCallback(function ($msg, $thread, $classification) use (&$captured): array {
            $captured = $classification;

            return ['content' => 'The image shows a cat.', 'metadata' => ['analysis_type' => 'vision']];
        });

        $runner = new FileAnalysisRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::FileAnalysis, [], ['prompt' => 'What is in this image?']);

        $result = $runner->run($node, $this->context($this->messageWithFile('What is in this image?')));

        self::assertTrue($result->isSuccessful());
        self::assertSame('The image shows a cat.', $result->text);
        self::assertSame('analyzefile', $captured['topic']);
        self::assertSame('file_analysis', $captured['intent']);
    }

    public function testFileAnalysisFailsWithoutAttachment(): void
    {
        $handler = $this->createMock(FileAnalysisHandler::class);
        $handler->expects(self::never())->method('handle');

        $runner = new FileAnalysisRunner($handler, $this->createMock(LoggerInterface::class));
        $node = new TaskNode('n1', Capability::FileAnalysis, [], ['prompt' => 'describe it']);

        $result = $runner->run($node, $this->context($this->message('describe it')));

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('no file', (string) $result->error);
    }

    public function testComposeReplyGathersTextAndAttachments(): void
    {
        $ctx = $this->context($this->message());
        $ctx->setResult('n2', NodeResult::ok('final summary'));
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio']]));

        $node = new TaskNode('n4', Capability::ComposeReply, ['n2', 'n3'], [
            'text' => '$n2.text',
            'attachments' => ['$n3.file'],
        ]);

        $result = (new ComposeReplyRunner())->run($node, $ctx);

        self::assertSame('final summary', $result->text);
        self::assertCount(1, $result->files);
        self::assertSame('audio', $result->files[0]['type']);
    }
}
