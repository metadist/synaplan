<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Media;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Message\AdvanceMediaJobCommand;
use App\MessageHandler\AdvanceMediaJobCommandHandler;
use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobCanceller;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobRealtimeNotifier;
use App\Service\Media\MediaJobReaper;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobStore;
use App\Service\Media\MediaJobUsageRecorder;
use App\Service\Media\SyncMediaJobGenerator;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\MessageApiFormatter;
use App\Service\RateLimitService;
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
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private RateLimitService&MockObject $rateLimitService;

    /** @var list<array{channel: string, event: string, payload: array<string, mixed>}> */
    private array $publishedEvents = [];

    /** @var list<array{action: string, meta: array<string, mixed>}> */
    private array $recordedUsage = [];

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

        // Spy publisher: capture every realtime event the terminal sync pushes
        // so we can assert push-on-completion end to end.
        $this->publishedEvents = [];
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);
        $this->realtimePublisher->method('publish')->willReturnCallback(
            function (ChannelInterface $channel, string $event, array $payload): void {
                $this->publishedEvents[] = ['channel' => $channel->name(), 'event' => $event, 'payload' => $payload];
            }
        );

        // Spy rate-limiter: capture every usage recording the terminal sync bills.
        $this->recordedUsage = [];
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->rateLimitService->method('recordUsage')->willReturnCallback(
            function (object $user, string $action, array $meta): void {
                $this->recordedUsage[] = ['action' => $action, 'meta' => $meta];
            }
        );

        $usageRecorder = new MediaJobUsageRecorder(
            $this->rateLimitService,
            $container->get(\App\Repository\UserRepository::class),
            $this->jobService,
            $jobLogger,
        );

        $this->messageSync = new MediaJobMessageSync(
            $container->get(\App\Repository\MessageRepository::class),
            $this->jobService,
            $container->get(GeneratedFileRegistrar::class),
            new MediaJobRealtimeNotifier($this->realtimePublisher, $this->jobService, $jobLogger),
            $usageRecorder,
            $container->get(\App\Service\Multitask\TaskPlanStore::class),
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

        // Sprint C: completion was pushed to the owner's user channel.
        $terminalPush = array_values(array_filter(
            $this->publishedEvents,
            static fn (array $e): bool => 'media_job.update' === $e['event'] && 'done' === ($e['payload']['state'] ?? null),
        ));
        self::assertNotEmpty($terminalPush, 'A media_job.update push must fire on completion');
        self::assertSame('user:'.$user->getId(), $terminalPush[0]['channel']);
        self::assertSame($message->getId(), $terminalPush[0]['payload']['message_id']);
        self::assertIsArray($terminalPush[0]['payload']['file']);

        // Sprint E: a completed render is billed exactly once.
        $videoBilling = array_values(array_filter(
            $this->recordedUsage,
            static fn (array $u): bool => 'VIDEOS' === $u['action'],
        ));
        self::assertCount(1, $videoBilling, 'a completed render must record usage exactly once');
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

    public function testCompletedJobStillBoundToIncomingMessageNeverClobbersPrompt(): void
    {
        // Issue #1218: the job is created bound to the INCOMING message id and
        // only rebound to the OUT message once StreamController has persisted
        // it. If a fast image render completes on the worker BEFORE that rebind,
        // the terminal sync hits the IN row. It must NOT clear the user's prompt
        // (data loss) nor pin the generated image to their own bubble — the
        // direction guard leaves the IN message completely untouched.
        $user = $this->createUser();
        $incoming = $this->createIncomingMessage($user, 'erstelle ein Bild einer Katze und beschreibe es');

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_IMAGE,
            'provider' => 'openai',
            'prompt' => 'a cat',
            'model' => 'gpt-image-1',
            'messageId' => $incoming->getId(), // rebind lost the race → still IN
            'options' => ['lang' => 'de'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();
        $this->jobService->markCompleted($job, [
            'file' => ['url' => '/api/v1/files/uploads/cat.png', 'type' => 'image'],
            'provider' => 'openai',
            'model' => 'gpt-image-1',
        ]);

        $this->messageSync->syncTerminalState($job);

        $this->em->refresh($incoming);
        self::assertSame('erstelle ein Bild einer Katze und beschreibe es', $incoming->getText(), 'IN prompt must be preserved');
        self::assertSame(0, $incoming->getFiles()->count(), 'generated image must not attach to the IN message');
        self::assertNull($incoming->getMeta('media_job'), 'no media_job meta must be written to the IN message');

        // The realtime push must also be suppressed while bound to IN: it carries
        // the IN message_id + file, so publishing it would make the client patch
        // the user bubble and append the image there — the same "image on the
        // user's message" bug through realtime (Copilot review, PR #1219).
        $pushedForIncoming = array_values(array_filter(
            $this->publishedEvents,
            static fn (array $e): bool => 'media_job.update' === $e['event'] && ($e['payload']['message_id'] ?? null) === $incoming->getId(),
        ));
        self::assertSame([], $pushedForIncoming, 'no media_job.update may be pushed for the IN message');
    }

    public function testTerminalJobLateRebindSyncsOutMessageAndHealsTaskCard(): void
    {
        // Issue #1239: an async DAG image node finishes BEFORE StreamController
        // persists the OUT message. The terminal sync correctly skips the IN
        // message (#1218 direction guard) — but the later rebind must then give
        // the job its missed sync against the OUT message: heal the persisted
        // task card to `done` with the file, attach the File entity, update the
        // BMESSAGE_TASKS row, and NEVER touch the assembled reply text or set
        // the single-task `media_job` banner meta on a DAG turn.
        $user = $this->createUser();
        $incoming = $this->createIncomingMessage($user, 'erstelle das bild einer katze dann schreibe ein gedicht darüber');

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_IMAGE,
            'provider' => 'google',
            'prompt' => 'a cat',
            'model' => 'gemini-image',
            'messageId' => $incoming->getId(),
            'nodeId' => 'n1',
            'options' => ['lang' => 'de'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();
        $this->jobService->markCompleted($job, [
            'file' => ['url' => '/api/v1/files/uploads/cat-1239.png', 'type' => 'image'],
            'provider' => 'google',
            'model' => 'gemini-image',
        ]);

        // Worker sync fires while still bound to IN → skipped (existing guard).
        $this->messageSync->syncTerminalState($job);
        $this->em->refresh($incoming);
        self::assertSame(0, $incoming->getFiles()->count());

        // StreamController persists the OUT message: assembled reply text + the
        // per-node render state with the image card still 'running'.
        $assembledText = "Hier ist dein Gedicht über die Katze.\n\nMiau.";
        $outgoing = $this->createMessage($user);
        $outgoing->setText($assembledText);
        $outgoing->setMeta('task_plan', (string) json_encode([
            'reply_node' => 'n3',
            'cards' => [
                ['nodeId' => 'n1', 'capability' => 'image_generation', 'kind' => 'image', 'state' => 'running', 'job_id' => $job->getJobKey()],
                ['nodeId' => 'n2', 'capability' => 'chat', 'kind' => 'text', 'state' => 'done', 'text' => 'Miau.'],
            ],
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        $this->em->flush();

        // The DAG also persisted the node row as running, stamped with the job key.
        $connection = $this->em->getConnection();
        $connection->insert('BMESSAGE_TASKS', [
            'BMESSAGEID' => $incoming->getId(),
            'BNODEID' => 'n1',
            'BCAPABILITY' => 'image_generation',
            'BDEPENDSON' => '[]',
            'BSTATUS' => 'running',
            'BJOBKEY' => $job->getJobKey(),
        ]);

        try {
            // StreamController's rebind loop: terminal job → rebind + missed sync.
            $rebound = $this->jobService->rebindMessage($job->getJobKey(), $outgoing->getId());
            self::assertNotNull($rebound, 'terminal jobs must still be rebindable');
            self::assertTrue($rebound->isTerminal());
            self::assertSame($outgoing->getId(), $rebound->getMessageId());
            $this->messageSync->syncTerminalState($rebound);

            $this->em->refresh($outgoing);

            // The assembled reply text survives — the single-task "clear text on
            // done" mutation must not run for node jobs.
            self::assertSame($assembledText, $outgoing->getText());
            // No single-task banner meta on a DAG turn.
            self::assertNull($outgoing->getMeta('media_job'));

            // The generated file is attached for reload + Files world.
            self::assertGreaterThan(0, $outgoing->getFiles()->count());
            if ($outgoing->getFiles()->count() > 0) {
                $this->createdFileIds[] = $outgoing->getFiles()->first()->getId();
            }

            // The persisted card resolved to done with the file URL.
            $taskPlan = json_decode((string) $outgoing->getMeta('task_plan'), true);
            self::assertIsArray($taskPlan);
            $imageCard = $taskPlan['cards'][0];
            self::assertSame('done', $imageCard['state']);
            self::assertSame('/api/v1/files/uploads/cat-1239.png', $imageCard['url']);
            self::assertSame('image', $imageCard['type']);
            // The unrelated card is untouched.
            self::assertSame('done', $taskPlan['cards'][1]['state']);
            self::assertSame('Miau.', $taskPlan['cards'][1]['text']);

            // The observability row healed too.
            $rowStatus = $connection->fetchOne(
                'SELECT BSTATUS FROM BMESSAGE_TASKS WHERE BJOBKEY = ?',
                [$job->getJobKey()],
            );
            self::assertSame('done', $rowStatus);

            // The realtime push carries the node id so the live card resolves.
            $nodePush = array_values(array_filter(
                $this->publishedEvents,
                static fn (array $e): bool => 'media_job.update' === $e['event']
                    && 'n1' === ($e['payload']['node_id'] ?? null)
                    && 'done' === ($e['payload']['state'] ?? null),
            ));
            self::assertNotEmpty($nodePush, 'terminal node sync must push a media_job.update with the node id');
            self::assertSame($outgoing->getId(), $nodePush[0]['payload']['message_id']);
        } finally {
            $connection->delete('BMESSAGE_TASKS', ['BJOBKEY' => $job->getJobKey()]);
        }
    }

    public function testTerminalSaveAdoptsReboundMessageIdFromStore(): void
    {
        // Issue #1239, worker-side half: a single-step image render holds its
        // MediaJob in memory for the whole render. If StreamController rebinds
        // the job to the OUT message DURING that render, the worker's terminal
        // markCompleted() used to save the stale IN-message binding back over
        // the rebind — the sync then hit the IN row and was skipped forever.
        // The terminal transition must re-adopt the freshest stored binding.
        $user = $this->createUser();
        $incoming = $this->createIncomingMessage($user, 'erstelle das bild einer katze');
        $outgoing = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_IMAGE,
            'provider' => 'openai',
            'prompt' => 'a cat',
            'messageId' => $incoming->getId(),
            'nodeId' => 'n1',
            'options' => ['lang' => 'de'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        // Rebind lands while the worker's in-memory copy still points at IN.
        $this->jobService->rebindMessage($job->getJobKey(), $outgoing->getId());
        self::assertSame($incoming->getId(), $job->getMessageId(), 'in-memory copy is intentionally stale');

        $this->jobService->markCompleted($job, [
            'file' => ['url' => '/api/v1/files/uploads/cat-race.png', 'type' => 'image'],
        ]);

        self::assertSame($outgoing->getId(), $job->getMessageId(), 'terminal save must adopt the rebound OUT binding');
        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame($outgoing->getId(), $fresh->getMessageId());
    }

    public function testSyncImageJobRendersSavesAndAttachesToMessage(): void
    {
        $user = $this->createUser();
        $message = $this->createMessage($user);

        $job = $this->jobService->create([
            'userId' => $user->getId(),
            'type' => MediaJob::TYPE_IMAGE,
            'provider' => 'openai',
            'prompt' => 'a red balloon floating up',
            'model' => 'gpt-image-1',
            'messageId' => $message->getId(),
            'options' => ['size' => '1024x1024', 'lang' => 'en'],
        ]);
        $this->createdJobKeys[] = $job->getJobKey();

        // 1x1 transparent PNG as a base64 data URL — the generator decodes and
        // saves it (no network), mirroring how most image providers return data.
        $pngDataUrl = 'data:image/png;base64,'
            .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => [['url' => $pngDataUrl]],
            'provider' => 'openai',
            'model' => 'gpt-image-1',
        ]);
        $this->aiFacade->expects(self::never())->method('startVideoGeneration');
        $this->aiFacade->expects(self::never())->method('pollVideoOperation');

        // A single advance step renders, saves, and completes — no polling.
        $this->buildHandler()->__invoke(new AdvanceMediaJobCommand($job->getJobKey()));

        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_COMPLETED, $fresh->getStatus());

        $result = $fresh->getResult();
        self::assertIsArray($result);
        self::assertSame('image', $result['file']['type'] ?? null);
        $relative = substr((string) $result['file']['url'], strlen('/api/v1/files/uploads/'));
        self::assertFileExists($this->uploadDir.'/'.$relative);

        // Reload contract: file attached + legacy file field set + state done.
        $this->em->refresh($message);
        self::assertGreaterThan(0, $message->getFiles()->count());
        self::assertSame(1, $message->getFile());
        $meta = json_decode((string) $message->getMeta('media_job'), true);
        self::assertSame('done', $meta['state'] ?? null);

        if ($message->getFiles()->count() > 0) {
            $this->createdFileIds[] = $message->getFiles()->first()->getId();
        }
    }

    public function testCancelTransitionsJobSyncsMessageAndPushes(): void
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
        $job->setStatus(MediaJob::STATUS_RUNNING)->setProviderRef('op-1');
        $this->store->save($job);
        $this->createdJobKeys[] = $job->getJobKey();

        $this->aiFacade->expects(self::once())->method('cancelVideoOperation');

        $canceller = new MediaJobCanceller($this->jobService, $this->messageSync, $this->aiFacade, new NullLogger());
        self::assertTrue($canceller->cancel($job));

        $fresh = $this->store->find($job->getJobKey());
        self::assertNotNull($fresh);
        self::assertSame(MediaJob::STATUS_CANCELLED, $fresh->getStatus());

        $this->em->refresh($message);
        $meta = json_decode((string) $message->getMeta('media_job'), true);
        self::assertSame('cancelled', $meta['state']);

        $cancelPush = array_values(array_filter(
            $this->publishedEvents,
            static fn (array $e): bool => 'media_job.update' === $e['event'] && 'cancelled' === ($e['payload']['state'] ?? null),
        ));
        self::assertNotEmpty($cancelPush, 'cancel must push a terminal media_job.update');
    }

    public function testFindActiveForUserReturnsOnlyOwnActiveJobs(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        $jobA = $this->jobService->create(['userId' => $userA->getId(), 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'google']);
        $this->jobService->markRunning($jobA, 'opA');
        $jobB = $this->jobService->create(['userId' => $userB->getId(), 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'google']);
        $this->jobService->markRunning($jobB, 'opB');
        $this->createdJobKeys[] = $jobA->getJobKey();
        $this->createdJobKeys[] = $jobB->getJobKey();

        $keys = array_map(
            static fn (MediaJob $j): string => $j->getJobKey(),
            $this->jobService->findActiveForUser($userA->getId()),
        );

        self::assertContains($jobA->getJobKey(), $keys);
        self::assertNotContains($jobB->getJobKey(), $keys, 'must not leak another user\'s jobs');
    }

    public function testPerUserIndexCountsActiveAndDropsTerminalJobs(): void
    {
        $user = $this->createUser();

        $job1 = $this->jobService->create(['userId' => $user->getId(), 'type' => MediaJob::TYPE_VIDEO, 'provider' => 'google']);
        $this->jobService->markRunning($job1, 'op1');
        $job2 = $this->jobService->create(['userId' => $user->getId(), 'type' => MediaJob::TYPE_IMAGE, 'provider' => 'openai']);
        $this->jobService->markRunning($job2, 'op2');
        $this->createdJobKeys[] = $job1->getJobKey();
        $this->createdJobKeys[] = $job2->getJobKey();

        self::assertSame(2, $this->jobService->countActiveForUser($user->getId()));

        // Completing one removes it from the per-user index immediately.
        $this->jobService->markCompleted($job1, ['file' => ['url' => '/x.mp4', 'type' => 'video']]);

        self::assertSame(1, $this->jobService->countActiveForUser($user->getId()));
        $remaining = array_map(
            static fn (MediaJob $j): string => $j->getJobKey(),
            $this->jobService->findActiveForUser($user->getId()),
        );
        self::assertSame([$job2->getJobKey()], $remaining);
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
            new SyncMediaJobGenerator(
                $this->aiFacade,
                new \App\Service\File\UserUploadPathBuilder(),
                $this->createMock(\Symfony\Contracts\HttpClient\HttpClientInterface::class),
                new NullLogger(),
                $this->uploadDir,
            ),
            $this->lockFactory,
            new NullLogger(),
            self::getContainer()->get(\App\Repository\UserRepository::class),
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

    private function createIncomingMessage(User $user, string $text): Message
    {
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setTrackingId(0);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setMessageType('WW');
        $message->setText($text);
        $message->setDirection('IN');
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
