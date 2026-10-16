<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamChunkRelay;
use App\Service\Chat\Stream\StreamGeneratedFiles;
use App\Service\Chat\Stream\StreamMessageMeta;
use App\Service\Chat\Stream\StreamReply;
use App\Service\Chat\Stream\StreamReplyPersister;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamTurnState;
use App\Service\Chat\Stream\StreamUserInput;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobRebinder;
use App\Service\Realtime\SseWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The stored text of a streamed reply never shows a raw document envelope.
 *
 * A prose-wrapped or malformed {"BFILEPATH", …} envelope that cannot become
 * a file is stored as the failure marker the client translates (#1406), and
 * goes out live when the client received no data chunk yet. A document the
 * tool loop already wrote wins over the marker.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreamReplyPersisterTest extends TestCase
{
    private const MALFORMED_ENVELOPE = 'Here is your file: {"BFILEPATH":"slides.pptx","BFILETEXT":"unterminated';

    private LoggerInterface&MockObject $logger;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    public function testMalformedOfficemakerEnvelopeIsReplacedWithFailureMarker(): void
    {
        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'StreamController: officemaker reply carried an unparseable file envelope; suppressing raw blob',
                ['text_length' => strlen(self::MALFORMED_ENVELOPE)],
            );

        [$reply, $frames] = $this->persist(self::MALFORMED_ENVELOPE, 'officemaker');

        self::assertSame('__FILE_GENERATION_FAILED__', $reply->text);
        self::assertSame('__FILE_GENERATION_FAILED__', $reply->outgoing->getText());
        self::assertContains(['status' => 'data', 'chunk' => '__FILE_GENERATION_FAILED__'], $frames, 'the marker must reach a client that saw no data chunk');
    }

    public function testRegularTextAndOtherTopicsRemainUntouched(): void
    {
        $this->logger->expects(self::never())->method('warning');

        self::assertSame('Regular reply', $this->persist('Regular reply', 'officemaker')[0]->text);
        self::assertSame(self::MALFORMED_ENVELOPE, $this->persist(self::MALFORMED_ENVELOPE, 'chat')[0]->text);
    }

    public function testADocumentTheToolLoopWroteWinsOverTheFailureMarker(): void
    {
        $file = new File();
        $file->setUserId(7);
        $file->setFileName('slides.pptx');
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
        $this->em->method('getRepository')->willReturn($files);

        [$reply] = $this->persist(self::MALFORMED_ENVELOPE, 'officemaker', ['generated_file' => ['id' => 5]]);

        self::assertSame('__FILE_GENERATED__:slides.pptx', $reply->text);
        self::assertSame($file, $reply->generatedFile);
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{0: StreamReply, 1: list<array<string, mixed>>}
     */
    private function persist(string $responseText, string $topic, array $metadata = []): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $turn = new StreamTurnRequest(
            caller: new StreamCaller($user, 'en'),
            chatId: '3',
            trackId: '4242',
            input: new StreamUserInput('Make me slides'),
            options: new StreamTurnOptions(),
        );
        $state = new StreamTurnState($turn, new PerfTimer(), ['id' => null, 'name' => null, 'provider' => null]);
        $state->incoming = (new Message())->setUserId(7)->setProviderIndex('WEB')->setText('Make me slides');

        $sse = new SseWriter(captureMode: true);
        $relay = new StreamChunkRelay($sse, $this->createStub(MediaCancellationStore::class), '4242');
        $relay->responseText = $responseText;

        $persister = new StreamReplyPersister(
            $this->em,
            $this->createStub(StreamGeneratedFiles::class),
            new StreamMessageMeta($this->logger),
            $this->createStub(MediaJobRebinder::class),
            $this->logger,
        );

        ob_start();
        try {
            $reply = $persister->persist($sse, $turn, $state, [
                'success' => true,
                'classification' => ['topic' => $topic, 'language' => 'en'],
                'response' => ['content' => $responseText, 'metadata' => $metadata],
            ], $relay);
        } finally {
            $raw = (string) ob_get_clean();
        }

        $frames = [];
        foreach (preg_split("/\n\n+/", trim($raw)) ?: [] as $frame) {
            if (str_starts_with($frame, 'data: ')) {
                $frames[] = json_decode(substr($frame, 6), true);
            }
        }

        return [$reply, $frames];
    }
}
