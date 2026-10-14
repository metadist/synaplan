<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\VoiceSession;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Usage\RecordedUsage;
use App\Service\Voice\VoiceLiveArtifact;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A spoken request that made something must reach the chat as the same
 * media message the typed chat shows, and the voice must never read a
 * storage marker aloud.
 */
final class VoiceLiveArtifactTest extends TestCase
{
    private const ANSWER_ID = 501;
    private const SESSION_ID = 77;
    private const CHAT_ID = 12;

    /** @var list<Message> */
    private array $persisted = [];

    public function testAPlainAnswerMakesNoArtifact(): void
    {
        $made = $this->artifacts()->capture($this->session(), $this->question(), [
            'response' => ['content' => 'It is four.', 'metadata' => ['model_id' => 5]],
        ], [], false);

        self::assertNull($made);
        self::assertSame([], $this->persisted);
    }

    public function testAFinishedPictureBecomesAChatMessageAndIsAnnouncedWithoutTheMarker(): void
    {
        $made = $this->artifacts()->capture($this->session(), $this->question(), [
            'response' => [
                'content' => '__IMAGE_GENERATED__',
                'metadata' => [
                    'provider' => 'openai',
                    'model' => 'gpt-image-1',
                    'media_type' => 'image',
                    'media_prompt' => 'a fox in the snow',
                    'file' => ['path' => '/api/v1/files/uploads/4/fox.png', 'type' => 'image'],
                ],
            ],
            'classification' => ['topic' => 'mediamaker', 'language' => 'de'],
        ], ['topic' => 'mediamaker', 'chat' => ['provider' => 'openai', 'model' => 'gpt-image-1']], false);

        self::assertNotNull($made);
        self::assertSame('The image is ready. You can see it in the chat now.', $made['spoken']);
        self::assertStringNotContainsString('__', $made['spoken']);
        self::assertSame(['type' => 'image', 'state' => 'completed', 'messageId' => self::ANSWER_ID], $made['artifact']);

        $answer = $this->persisted[0];
        self::assertSame('OUT', $answer->getDirection());
        self::assertSame('__IMAGE_GENERATED__', $answer->getText());
        self::assertSame(1, $answer->getFile());
        self::assertSame('/api/v1/files/uploads/4/fox.png', $answer->getFilePath());
        self::assertSame('image', $answer->getFileType());
        self::assertSame('mediamaker', $answer->getTopic());
        self::assertSame('de', $answer->getLanguage());
        self::assertSame('a fox in the snow', $answer->getMeta('media_prompt'));
        self::assertSame('live_voice', $answer->getMeta('source'));
        self::assertSame((string) self::SESSION_ID, $answer->getMeta(VoiceLiveArtifact::META_SESSION));
        self::assertNull($answer->getMeta('voice_session_id'));
        self::assertSame('gpt-image-1', $answer->getMeta('ai_chat_model'));
    }

    public function testABackgroundVideoMovesToTheAnswerAndIsAnnouncedAsRunning(): void
    {
        $jobs = $this->createMock(MediaJobService::class);
        $jobs->expects($this->once())->method('rebindMessage')->with('job-1', self::ANSWER_ID)->willReturn(null);

        $made = $this->artifacts($jobs)->capture($this->session(), $this->question(), [
            'response' => [
                'content' => '__VIDEO_GENERATING__',
                'metadata' => [
                    'media_type' => 'video',
                    'media_job' => ['job_id' => 'job-1', 'type' => 'video', 'state' => 'running'],
                ],
            ],
        ], [], false);

        self::assertNotNull($made);
        self::assertSame('running', $made['artifact']['state']);
        self::assertSame('video', $made['artifact']['type']);
        self::assertStringContainsString('creating the video now', $made['spoken']);
        self::assertStringNotContainsString('__', $made['spoken']);
        self::assertSame(
            '{"job_id":"job-1","type":"video","state":"running"}',
            $this->persisted[0]->getMeta('media_job'),
        );
    }

    public function testAJobThatFinishedBeforeTheAnswerExistedGetsItsSync(): void
    {
        $job = $this->createStub(MediaJob::class);
        $job->method('isTerminal')->willReturn(true);
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('rebindMessage')->willReturn($job);
        $sync = $this->createMock(MediaJobMessageSync::class);
        $sync->expects($this->once())->method('syncTerminalState')->with($job);

        $this->artifacts($jobs, $sync)->capture($this->session(), $this->question(), [
            'response' => [
                'content' => '__IMAGE_GENERATING__',
                'metadata' => ['media_job' => ['job_id' => 'job-2', 'type' => 'image', 'state' => 'running']],
            ],
        ], [], false);
    }

    public function testAFailedRenderSpeaksTheReasonAndStillShowsTheOutcome(): void
    {
        $made = $this->artifacts()->capture($this->session(), $this->question(), [
            'response' => [
                'content' => 'The video could not be created: the provider is busy.',
                'metadata' => ['media_job' => ['job_id' => 'job-3', 'type' => 'video', 'state' => 'failed', 'error' => 'busy']],
            ],
        ], [], false);

        self::assertNotNull($made);
        self::assertSame('failed', $made['artifact']['state']);
        self::assertSame('The video could not be created: the provider is busy.', $made['spoken']);
    }

    public function testAWrittenDocumentMovesFromTheQuestionToTheAnswer(): void
    {
        $document = (new File())->setFileName('report.docx')->setFilePath('4/report.docx')->setSource('generated');
        $question = $this->question();
        $question->addFile($document);

        $made = $this->artifacts()->capture($this->session(), $question, [
            'response' => [
                'content' => '__FILE_GENERATED__:report.docx',
                'metadata' => ['generated_file' => ['id' => 9, 'filename' => 'report.docx', 'path' => '4/report.docx']],
            ],
        ], [], false);

        self::assertNotNull($made);
        self::assertSame('document', $made['artifact']['type']);
        self::assertSame('The document is ready. You can open it in the chat now.', $made['spoken']);
        self::assertCount(0, $question->getFiles());
        self::assertSame([$document], $this->persisted[0]->getFiles()->toArray());
        self::assertSame(0, $this->persisted[0]->getFile());
    }

    public function testAnUploadOnTheQuestionStaysWithTheQuestion(): void
    {
        $upload = (new File())->setFileName('notes.pdf')->setFilePath('4/notes.pdf')->setSource('upload');
        $question = $this->question();
        $question->addFile($upload);

        $made = $this->artifacts()->capture($this->session(), $question, [
            'response' => ['content' => 'The notes say four.', 'metadata' => []],
        ], [], false);

        self::assertNull($made);
        self::assertCount(1, $question->getFiles());
    }

    public function testABilledPictureListsItsCostOnTheAnswer(): void
    {
        $this->artifacts()->capture($this->session(), $this->question(), [
            'response' => [
                'content' => '__IMAGE_GENERATED__',
                'metadata' => [
                    'provider' => 'openai',
                    'model' => 'gpt-image-1',
                    'file' => ['path' => '/api/v1/files/uploads/4/fox.png', 'type' => 'image'],
                    'media_recorded_usage' => new RecordedUsage('0.040000', '0.040000', 0, 0, 0),
                ],
            ],
        ], ['topic' => 'mediamaker'], false);

        $extra = json_decode((string) $this->persisted[0]->getMeta('ai_usage_extra'), true);
        self::assertIsArray($extra);
        self::assertSame('IMAGE', $extra[0]['kind'] ?? null);
        self::assertSame('0.040000', $extra[0]['cost'] ?? null);
    }

    public function testAnIncognitoCallStoresNothingAndCarriesTheRowItself(): void
    {
        $made = $this->artifacts()->capture($this->session(), $this->question(), [
            'response' => [
                'content' => '__IMAGE_GENERATED__',
                'metadata' => [
                    'media_type' => 'image',
                    'file' => ['path' => '/api/v1/files/uploads/4/fox.png', 'type' => 'image'],
                ],
            ],
        ], [], true);

        self::assertNotNull($made);
        self::assertSame([], $this->persisted);
        self::assertArrayNotHasKey('messageId', $made['artifact']);
        $row = $made['artifact']['row'] ?? null;
        self::assertIsArray($row);
        self::assertSame('OUT', $row['direction']);
        self::assertSame(['path' => '/api/v1/files/uploads/4/fox.png', 'type' => 'image'], $row['file']);
    }

    private function artifacts(?MediaJobService $jobs = null, ?MediaJobMessageSync $sync = null): VoiceLiveArtifact
    {
        $chat = (new Chat())->setUserId(4);
        $this->setId($chat, self::CHAT_ID);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $class): ?Chat => Chat::class === $class ? $chat : null);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Message) {
                $this->setId($entity, self::ANSWER_ID);
                $this->persisted[] = $entity;
            }
        });

        return new VoiceLiveArtifact(
            $em,
            $jobs ?? $this->createStub(MediaJobService::class),
            $sync ?? $this->createStub(MediaJobMessageSync::class),
            $this->createStub(GeneratedFileRegistrar::class),
            new NullLogger(),
        );
    }

    private function session(): VoiceSession
    {
        $session = (new VoiceSession())->setUserId(4)->setChatId(self::CHAT_ID)->setStatus('live');
        $this->setId($session, self::SESSION_ID);

        return $session;
    }

    private function question(): Message
    {
        return (new Message())
            ->setUserId(4)
            ->setTrackingId(1)
            ->setText('Draw a fox in the snow')
            ->setDirection('IN')
            ->setLanguage('de')
            ->setTopic('CHAT');
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
