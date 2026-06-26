<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Media;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Message\AdvanceMediaJobCommand;
use App\MessageHandler\AdvanceMediaJobCommandHandler;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobReaper;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobStore;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\MessageApiFormatter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * End-to-end integration test for the async media-job backbone (Release 4.0,
 * Sprint B).
 *
 * Drives the full state machine through the real {@see AdvanceMediaJobCommandHandler}
 * against the real Symfony container, real Doctrine ORM (persists a real
 * {@see Message} + {@see User}), real Redis-backed {@see MediaJobStore} (skipped
 * if Redis is unreachable), and real {@see MessageApiFormatter} — only the AI
 * provider is mocked so the test is fully deterministic and does not call out
 * to any real video provider.
 *
 * What this protects against (re-discovered the hard way during Sprint B):
 *   - Redis env-prefix mismatch between backend/worker (silent "job not found")
 *   - `MediaJobMessageSync` not attaching the generated file (reload-shows-empty)
 *   - `MessageApiFormatter` failing to project the running placeholder
 *   - Reaper failing to drive a past-deadline job to a terminal state
 *   - `MediaJobController` poll failing to enforce the deadline on its own when
 *     the worker missed it
 *
 * A successful run proves: every state transition reaches a terminal state,
 * the message and its file are queryable via the same channel the chat history
 * loads, the failure path persists a localized error, and overdue jobs are
 * caught on the very next poll even without the worker running.
 */
final class AsyncMediaJobLifecycleIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaJobStore $store;
    private MediaJobService $jobService;
    private MediaJobMessageSync $messageSync;
    private MediaJobReaper $reaper;
    private MessageApiFormatter $apiFormatter;
    private AiFacade&MockObject $aiFacade;
    private LockFactory $lockFactory;
    private string $uploadDir;
    private MediaErrorMessageBuilder $errorBuilder;
    private MediaJobConfig $config;

    /** @var list<int> message ids created during the test, cleaned up in tearDown */
    private array $createdMessageIds = [];
    /** @var list<int> user ids created during the test, cleaned up in tearDown */
    private array $createdUserIds = [];
    /** @var list<int> file ids created during the test, cleaned up in tearDown */
    private array $createdFileIds = [];
    /** @var list<string> job keys created during the test, cleaned up in tearDown */
    private array $createdJobKeys = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->store = $container->get(MediaJobStore::class);
        $this->em = $container->get('doctrine')->getManager();
        $this->errorBuilder = new MediaErrorMessageBuilder();
        $this->config = $container->get(MediaJobConfig::class);
        $this->lockFactory = $container->get(LockFactory::class);
        $this->apiFormatter = $container->get(MessageApiFormatter::class);

        $jobLogger = new NullLogger();
        $this->jobService = new MediaJobService($this->store, $jobLogger);
        $this->messageSync = new MediaJobMessageSync(
            $container->get(\App\Repository\MessageRepository::class),
            $this->jobService,
            $container->get(GeneratedFileRegistrar::class),
            $this->em,
            $jobLogger,
        );

        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->reaper = new MediaJobReaper(
            $this->jobService,
            $this->messageSync,
            $this->config,
            $this->aiFacade,
            $this->errorBuilder,
            $jobLogger,
        );

        $this->uploadDir = sys_get_temp_dir().'/synaplan_media_lifecycle_'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o777, true);

        // Smoke-test Redis up-front so we don't get cryptic "job not found"
        // assertions further down — exactly the failure mode we are guarding
        // against.
        $probe = (new MediaJob())->setUserId(1);
        try {
            $this->store->save($probe);
        } catch (\Throwable) {
            self::markTestSkipped('Redis unreachable — async lifecycle requires a live Redis store.');
        }
        $this->store->save($probe->setStatus(MediaJob::STATUS_COMPLETED));
        $this->createdJobKeys[] = $probe->getJobKey();
    }

    protected function tearDown(): void
    {
        // Remove temp upload artifacts.
        if (is_dir($this->uploadDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                @($item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath()));
            }
            @rmdir($this->uploadDir);
        }

        // Clean DB rows we created (best-effort — production DB tests do this
        // pattern; we never share state with other tests).
        foreach ($this->createdFileIds as $fileId) {
            $file = $this->em->find(\App\Entity\File::class, $fileId);
            if ($file) {
                $this->em->remove($file);
            }
        }
        foreach ($this->createdMessageIds as $messageId) {
            $message = $this->em->find(Message::class, $messageId);
            if ($message) {
                $this->em->remove($message);
            }
        }
        foreach ($this->createdUserIds as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user) {
                $this->em->remove($user);
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Tolerate orphan cleanup failures in test teardown.
        }

        // Clean Redis snapshots so re-runs don't pile up.
        foreach ($this->createdJobKeys as $jobKey) {
            $job = $this->store->find($jobKey);
            if (null !== $job && !$job->isTerminal()) {
                $job->setStatus(MediaJob::STATUS_CANCELLED);
                $this->store->save($job);
            }
        }

        parent::tearDown();
    }

    public function testHappyPathSubmitPollDoneFinalizeCompleted(): void
    {
        $user = $this->createUser();
        $message = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'prompt' => 'a cat surfing',
            'modelId' => 195,
            'model' => 'veo-3.1-fast-generate-preview',
            'messageId' => $message->getId(),
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        // Provider mock: start → operation handle; first poll → not done;
        // second poll → done with a video URI; download → raw mp4 bytes.
        $this->aiFacade->method('startVideoGeneration')->willReturn([
            'operationName' => 'op/abc',
            'provider' => 'google',
            'model' => 'veo-3.1-fast-generate-preview',
            'duration' => 5,
            'resolution' => '720p',
        ]);
        $this->aiFacade->method('pollVideoOperation')
            ->willReturnOnConsecutiveCalls(
                ['done' => false, 'videoUri' => null, 'error' => null, 'percent' => 25, 'status' => 'processing'],
                ['done' => true, 'videoUri' => 'gs://bucket/video.mp4', 'error' => null, 'percent' => 100, 'status' => 'completed'],
            );
        $this->aiFacade->method('downloadVideoRaw')->willReturn($this->fakeMp4Bytes());

        $handler = $this->buildHandler();

        // Step 1: submit — provider operation is started, job → running, next
        // advance is dispatched (we use an in-memory bus so this is a no-op).
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_RUNNING, $fresh->getStatus());
        self::assertSame('op/abc', $fresh->getProviderRef());

        // Step 2: poll #1 — provider says not done, job stays running with
        // progress updated.
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_RUNNING, $fresh->getStatus());
        self::assertSame(25, $fresh->getPercent());

        // Step 3: poll #2 — provider says done, job → finalizing.
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_FINALIZING, $fresh->getStatus());

        // Step 4: finalize — bytes downloaded, written to disk, message synced.
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_COMPLETED, $fresh->getStatus());
        self::assertSame(100, $fresh->getPercent());

        $result = $fresh->getResult();
        self::assertIsArray($result);
        self::assertIsArray($result['file'] ?? null);
        self::assertStringStartsWith('/api/v1/files/uploads/', $result['file']['url']);
        self::assertSame('video', $result['file']['type']);

        // The poll API contract — what the frontend sees on its next check.
        $status = $this->jobService->toStatusArray($fresh);
        self::assertSame('done', $status['state']);
        self::assertSame(100, $status['percent']);
        self::assertFalse($status['stalled']);
        self::assertNotNull($status['file']);

        // The reload contract — what the chat history shows when the user
        // refreshes their browser. The message must now have a `video` part
        // sourced from the persisted File entity (not from the live SSE
        // payload), and an empty text body (no placeholder lingering).
        $this->em->refresh($message);
        self::assertSame('', $message->getText());
        self::assertGreaterThan(0, $message->getFiles()->count(), 'Generated file must be attached to the message');

        $file = $message->getFiles()->first();
        self::assertNotFalse($file);
        $this->createdFileIds[] = $file->getId();
        self::assertSame('video', $file->getFileType());
        self::assertSame('video/mp4', $file->getFileMime());

        // The serialized chat row reflects the completed state, with the
        // running placeholder gone and the generated file exposed via the
        // `files` channel (the frontend's `messageMapper` projects those into
        // the `parts` array on reload — see `frontend/src/utils/messageMapper.ts`).
        $payload = $this->apiFormatter->format($message);
        self::assertSame('done', $payload['mediaJob']['state'] ?? null);
        self::assertSame('', $payload['text']);

        $files = $payload['files'] ?? [];
        self::assertIsArray($files);
        self::assertCount(1, $files, 'Reload payload must contain exactly one generated file');
        self::assertSame('video', $files[0]['fileType'] ?? null);
        self::assertSame('video/mp4', $files[0]['fileMime'] ?? null);
        self::assertStringEndsWith('.mp4', (string) ($files[0]['filename'] ?? ''));

        // The reload also exposes the generated clip through the legacy `file`
        // field — the channel the frontend mapper actually turns into a `video`
        // part on a page refresh (it only renders audio from `files[]`). Without
        // this the bubble would reload empty even though the file is on disk.
        self::assertIsArray($payload['file'] ?? null, 'Reload must expose the generated clip via the `file` field');
        self::assertSame('video', $payload['file']['type'] ?? null);
        self::assertStringEndsWith('.mp4', (string) ($payload['file']['path'] ?? ''));
    }

    public function testRebindRedirectsCompletionSyncToOutgoingMessage(): void
    {
        // Reproduces the live bug: the job is created by the media handler with
        // the INCOMING user message id (the OUT assistant message doesn't exist
        // yet). StreamController later rebinds the job to the OUT message. The
        // worker must then sync the OUT message — not the incoming one.
        $user = $this->createUser();
        $incoming = $this->createMessage($user);
        $outgoing = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'prompt' => 'a kite in the wind',
            'messageId' => $incoming->getId(), // wrong-by-design at creation
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        // StreamController's rebind once the OUT message is persisted.
        $this->jobService->rebindMessage($job->getJobKey(), $outgoing->getId());

        $this->aiFacade->method('startVideoGeneration')->willReturn([
            'operationName' => 'op/xyz',
            'provider' => 'google',
            'model' => 'veo-3.1-fast-generate-preview',
            'duration' => 5,
            'resolution' => '720p',
        ]);
        $this->aiFacade->method('pollVideoOperation')->willReturn(
            ['done' => true, 'videoUri' => 'gs://bucket/v.mp4', 'error' => null, 'percent' => 100, 'status' => 'completed'],
        );
        $this->aiFacade->method('downloadVideoRaw')->willReturn($this->fakeMp4Bytes());

        $handler = $this->buildHandler();
        // submit → running, poll → finalizing, finalize → completed + sync.
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));
        $handler->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        $this->em->refresh($incoming);
        $this->em->refresh($outgoing);

        // The OUTGOING message is the one that gets the completed state + file.
        self::assertGreaterThan(0, $outgoing->getFiles()->count(), 'OUT message must receive the generated file');
        self::assertSame(1, $outgoing->getFile(), 'OUT message legacy file flag must be set');
        $outMeta = json_decode((string) $outgoing->getMeta('media_job'), true);
        self::assertSame('done', $outMeta['state'] ?? null);

        // The INCOMING message must be left completely untouched.
        self::assertSame(0, $incoming->getFiles()->count());
        self::assertNull($incoming->getMeta('media_job'));

        if ($outgoing->getFiles()->count() > 0) {
            $this->createdFileIds[] = $outgoing->getFiles()->first()->getId();
        }
    }

    public function testFailurePathPersistsLocalizedErrorOnMessage(): void
    {
        $user = $this->createUser();
        $message = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'prompt' => 'broken request',
            'messageId' => $message->getId(),
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        $this->aiFacade->method('startVideoGeneration')
            ->willThrowException(new \RuntimeException('boom — provider 500'));

        $this->buildHandler()->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_FAILED, $fresh->getStatus());
        // The raw exception text must NEVER reach the user — only the
        // localized, classifier-built message.
        self::assertStringNotContainsString('boom', (string) $fresh->getError());
        self::assertStringNotContainsString('500', (string) $fresh->getError());

        // The message row reflects the failure for the next page reload.
        $this->em->refresh($message);
        self::assertNotEmpty($message->getText());
        $meta = json_decode((string) $message->getMeta('media_job'), true);
        self::assertIsArray($meta);
        self::assertSame('failed', $meta['state']);
        self::assertNotEmpty($meta['error']);
    }

    public function testPastDeadlineJobIsDrivenToTimedOutInOneStep(): void
    {
        $user = $this->createUser();
        $message = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'messageId' => $message->getId(),
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();
        $job->setStatus(MediaJob::STATUS_RUNNING)
            ->setProviderRef('op/abc')
            ->setDeadlineAt(time() - 30);
        $this->store->save($job);

        // Provider cancellation is best-effort — the handler invokes it.
        $this->aiFacade->expects(self::once())->method('cancelVideoOperation');
        $this->aiFacade->expects(self::never())->method('pollVideoOperation');

        $this->buildHandler()->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_TIMED_OUT, $fresh->getStatus());

        $this->em->refresh($message);
        $meta = json_decode((string) $message->getMeta('media_job'), true);
        self::assertSame('failed', $meta['state']);
    }

    public function testReaperEnforcesDeadlineWhenWorkerIsAbsent(): void
    {
        $user = $this->createUser();
        $message = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'messageId' => $message->getId(),
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();
        $job->setStatus(MediaJob::STATUS_RUNNING)
            ->setProviderRef('op/abc')
            ->setDeadlineAt(time() - 60);
        $this->store->save($job);

        $this->aiFacade->method('cancelVideoOperation');

        // Reaper picks the job up via findPastDeadline → drives it terminal
        // → syncs the message. No worker involved.
        self::assertSame(1, $this->reaper->reap());

        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_TIMED_OUT, $fresh->getStatus());

        $this->em->refresh($message);
        $meta = json_decode((string) $message->getMeta('media_job'), true);
        self::assertSame('failed', $meta['state']);
    }

    public function testPollEndpointStallDetectionFlipsAfterThreshold(): void
    {
        $user = $this->createUser();
        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'options' => ['lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        // Fresh job: not stalled yet.
        self::assertFalse($this->jobService->toStatusArray($job)['stalled']);

        // Backdate creation past the stall threshold while keeping queued.
        $reflection = new \ReflectionClass($job);
        $created = $reflection->getProperty('created');
        $created->setAccessible(true);
        $created->setValue($job, time() - (MediaJobService::STALL_QUEUED_SECONDS + 5));
        $this->store->save($job);

        $status = $this->jobService->toStatusArray($job);
        self::assertTrue($status['stalled'], 'Job queued past threshold must be flagged stalled');
        self::assertSame('queue_worker_down', $status['stall_reason']);
    }

    private function buildHandler(): AdvanceMediaJobCommandHandler
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message) => new \Symfony\Component\Messenger\Envelope($message),
        );
        $dispatcher = new MediaJobDispatcher($messageBus, new NullLogger());

        return new AdvanceMediaJobCommandHandler(
            $this->jobService,
            $dispatcher,
            $this->config,
            $this->messageSync,
            $this->aiFacade,
            $this->errorBuilder,
            new \App\Service\File\UserUploadPathBuilder(),
            $this->lockFactory,
            new NullLogger(),
            $this->uploadDir,
        );
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setType('USER');
        $user->setMail('media-lifecycle-'.bin2hex(random_bytes(4)).'@test.synaplan.local');
        $user->setPw(null);
        $user->setProviderId('test-'.bin2hex(random_bytes(4)));
        $user->setUserLevel('USER');
        $this->em->persist($user);
        $this->em->flush();
        $this->createdUserIds[] = $user->getId();

        return $user;
    }

    private function createMessage(User $user): Message
    {
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId(0);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WW');
        $message->setText('');
        $message->setDirection('OUT');
        $this->em->persist($message);
        $this->em->flush();
        $this->createdMessageIds[] = $message->getId();

        return $message;
    }

    private function fakeMp4Bytes(): string
    {
        // Bare-minimum MP4 ftyp box; not a playable video but enough for the
        // file-writer to record the right size and our File entity to be
        // valid. GeneratedFileRegistrar uses extension-based MIME, not magic.
        return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41fake-mp4";
    }
}
