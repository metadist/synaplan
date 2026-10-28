<?php

declare(strict_types=1);

namespace App\Tests\Unit\Observability;

use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallOutcome;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCallTimings;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\AiUsage;
use App\AI\Pipeline\Hook\AiCallMetricsHook;
use App\Controller\AdminCapacityController;
use App\EventSubscriber\RequestTimelineRecorder;
use App\Observability\RequestIdGenerator;
use App\Observability\RequestTimelineContext;
use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Service\Infrastructure\RedisService;
use App\Service\Observability\CapacityService;
use App\Service\Observability\CapacityTimelineService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit tests for the S1 AU13 request timeline recorder, {@see AiCallMetricsHook},
 * {@see RequestTimelineStore}, and the admin capacity `/requests` + `/ai-calls`
 * endpoints (`03_contracts.md` §9, `04_capacity_and_kubernetes.md` §2–§3, §4.2).
 */
final class RequestTimelineRecorderTest extends \PHPUnit\Framework\TestCase
{
    public function testChatStreamGatewayAndFileUploadRecordsCarryAllRequiredFields(): void
    {
        [$redis, $state] = $this->createFakeRedis();
        $context = new RequestTimelineContext();
        $store = new RequestTimelineStore($redis);
        $identity = new ServerIdentity('web1');
        $recorder = new RequestTimelineRecorder($context, $store, $identity);
        $hook = new AiCallMetricsHook($context);
        $kernel = $this->createStub(HttpKernelInterface::class);

        // 1. Chat stream request
        $chatReq = Request::create('/api/v1/messages/stream', 'POST');
        $chatReq->attributes->set('_route', 'api_messages_stream');
        $chatReq->attributes->set(RequestIdGenerator::ATTRIBUTE, 'req-chat-1');
        $recorder->onKernelRequest(new RequestEvent($kernel, $chatReq, HttpKernelInterface::MAIN_REQUEST));
        $recorder->onKernelController(new ControllerEvent($kernel, static fn () => null, $chatReq, HttpKernelInterface::MAIN_REQUEST));
        $context->setUserId(7);
        $context->recordDbQuery(4.2);
        $context->recordDbQuery(7.8);
        $hook->after(
            new AiCall(
                capability: AiCapability::ChatStream,
                provider: 'openai',
                model: 'gpt-4o',
                context: new AiCallContext(userId: 7, source: AiCallSource::ChatStream),
                payload: AiCallPayload::chat([['role' => 'user', 'content' => 'Hello']]),
                options: new AiCallOptions(),
            ),
            new AiCallResult(
                value: ['content' => 'Hi'],
                outcome: AiCallOutcome::Ok,
                usage: new AiUsage(promptTokens: 120, completionTokens: 45, totalTokens: 165, chargedCost: '0.001250'),
                timings: new AiCallTimings(hooksBeforeMs: 0.03, providerMs: 640.0, hooksAfterMs: 0.02, ttftMs: 180.0),
            ),
        );
        $context->recordTool('mcp', 'web_search', 'ok', 210);
        $context->recordEgress('https://api.openai.com/v1/chat/completions', 200, 640);
        $recorder->onKernelTerminate(new TerminateEvent($kernel, $chatReq, new Response('ok', 200)));

        // 2. Gateway call
        $gwReq = Request::create('/v1/messages', 'POST');
        $gwReq->attributes->set('_route', 'messages_api_create');
        $gwReq->attributes->set(RequestIdGenerator::ATTRIBUTE, 'req-gw-2');
        $recorder->onKernelRequest(new RequestEvent($kernel, $gwReq, HttpKernelInterface::MAIN_REQUEST));
        $recorder->onKernelController(new ControllerEvent($kernel, static fn () => null, $gwReq, HttpKernelInterface::MAIN_REQUEST));
        $hook->after(
            new AiCall(
                capability: AiCapability::Chat,
                provider: 'anthropic',
                model: 'claude-sonnet-4-6',
                context: new AiCallContext(userId: 9, source: AiCallSource::MessagesApi),
                payload: AiCallPayload::chat([['role' => 'user', 'content' => 'Gateway']]),
                options: new AiCallOptions(),
            ),
            new AiCallResult(
                value: ['content' => 'Done'],
                outcome: AiCallOutcome::Ok,
                usage: new AiUsage(promptTokens: 80, completionTokens: 20, totalTokens: 100, chargedCost: '0.000540'),
                timings: new AiCallTimings(hooksBeforeMs: 0.02, providerMs: 410.0, hooksAfterMs: 0.01, ttftMs: null),
            ),
        );
        $recorder->onKernelTerminate(new TerminateEvent($kernel, $gwReq, new Response('ok', 200)));

        // 3. File upload
        $uploadReq = Request::create('/api/v1/files/upload', 'POST');
        $uploadReq->attributes->set('_route', 'api_files_upload');
        $uploadReq->attributes->set(RequestIdGenerator::ATTRIBUTE, 'req-upload-3');
        $recorder->onKernelRequest(new RequestEvent($kernel, $uploadReq, HttpKernelInterface::MAIN_REQUEST));
        $recorder->onKernelController(new ControllerEvent($kernel, static fn () => null, $uploadReq, HttpKernelInterface::MAIN_REQUEST));
        $context->recordDbQuery(3.0);
        $recorder->onKernelTerminate(new TerminateEvent($kernel, $uploadReq, new Response('{"error":"server_busy"}', 503)));

        $recent = $store->recent();
        self::assertIsArray($recent);
        self::assertCount(3, $recent);

        $byId = [];
        foreach ($recent as $rec) {
            $byId[$rec['id']] = $rec;
        }

        $chatRec = $byId['req-chat-1'];
        self::assertSame('web1', $chatRec['server']);
        self::assertSame('chat_stream', $chatRec['route_class']);
        self::assertSame('2xx', $chatRec['status_class']);
        self::assertSame(12, $chatRec['db_ms']);
        self::assertSame(2, $chatRec['db_queries']);
        self::assertSame(7, $chatRec['user_id']);
        self::assertCount(1, $chatRec['ai']);
        self::assertSame('openai', $chatRec['ai'][0]['provider']);
        self::assertSame('gpt-4o', $chatRec['ai'][0]['model']);
        self::assertSame('chat_stream', $chatRec['ai'][0]['capability']);
        self::assertSame('chat_stream', $chatRec['ai'][0]['source']);
        self::assertSame(180, $chatRec['ai'][0]['ttft_ms']);
        self::assertSame(640, $chatRec['ai'][0]['duration_ms']);
        self::assertSame(120, $chatRec['ai'][0]['tokens_in']);
        self::assertSame(45, $chatRec['ai'][0]['tokens_out']);
        self::assertSame('0.001250', $chatRec['ai'][0]['cost']);
        self::assertSame('ok', $chatRec['ai'][0]['outcome']);
        self::assertSame([['source' => 'mcp', 'kind' => 'web_search', 'outcome' => 'ok', 'duration_ms' => 210]], $chatRec['tools']);
        self::assertSame([['destination' => 'api.openai.com', 'status_class' => '2xx', 'duration_ms' => 640]], $chatRec['egress']);

        $gwRec = $byId['req-gw-2'];
        self::assertSame('messages_api', $gwRec['route_class']);
        self::assertSame('messages_api', $gwRec['ai'][0]['source']);

        $upRec = $byId['req-upload-3'];
        self::assertSame('files', $upRec['route_class']);
        self::assertSame('busy', $upRec['status_class']);

        // Verify AdminCapacityController endpoints
        $timelineService = new CapacityTimelineService($store, $identity);
        $controller = new AdminCapacityController($this->createStub(CapacityService::class), $timelineService);
        $this->wireJsonSerializer($controller);

        $reqResp = $controller->requests();
        self::assertSame(200, $reqResp->getStatusCode());
        $reqPayload = json_decode((string) $reqResp->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($reqPayload['success']);
        self::assertSame('web1', $reqPayload['served_by']);
        self::assertCount(3, $reqPayload['route_classes']);
        self::assertCount(3, $reqPayload['slowest']);

        $aiResp = $controller->aiCalls();
        self::assertSame(200, $aiResp->getStatusCode());
        $aiPayload = json_decode((string) $aiResp->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($aiPayload['success']);
        self::assertCount(2, $aiPayload['models']);
        self::assertCount(2, $aiPayload['sources']);
        self::assertNotEmpty($state->hashes);
    }

    public function testBoundedLabelsReplaceUnknownModelWithCustomAndNeverExposeRawPath(): void
    {
        $context = new RequestTimelineContext();
        $context->start('req-bounded', RequestTimelineContext::classifyRoute(null, '/custom/secret-slug/12345'), null, 1730100000, 1_000_000_000);
        $context->recordAiCall(
            provider: 'Custom Provider!@#',
            model: 'user-private-finetune-secret-v9',
            capability: 'chat',
            source: 'chat_web',
            ttftMs: null,
            durationMs: 120.0,
            tokensIn: 10,
            tokensOut: 5,
            cost: '0.000100',
            outcome: 'ok',
            hooksMs: 0.04,
        );
        $record = $context->buildRecord('web1', '2xx', 1_050_000_000);

        self::assertSame('other', $record['route_class']);
        self::assertSame('custom', $record['ai'][0]['model']);
        self::assertSame('customprovider', $record['ai'][0]['provider']);
    }

    public function testPrivacyContractNeverStoresPromptApiKeyBearerTokenOrEmailInRedisPayload(): void
    {
        [$redis, $state] = $this->createFakeRedis();
        $context = new RequestTimelineContext();
        $store = new RequestTimelineStore($redis);
        $identity = new ServerIdentity('web1');
        $recorder = new RequestTimelineRecorder($context, $store, $identity);
        $hook = new AiCallMetricsHook($context);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $secretPrompt = 'My confidential medical diagnosis is XY-9988-SECRET';
        $secretApiKey = 'sk-proj-superSecretKeyValue987654321';
        $secretBearer = 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.secretPayload';
        $secretEmail = 'alice.private@example-corp.internal';

        $request = Request::create(
            '/api/v1/messages/stream?email='.urlencode($secretEmail).'&key='.$secretApiKey,
            'POST',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => $secretBearer,
                'HTTP_X_API_KEY' => $secretApiKey,
            ],
            json_encode(['message' => $secretPrompt, 'email' => $secretEmail], \JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_route', 'api_messages_stream');

        $recorder->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $recorder->onKernelController(new ControllerEvent($kernel, static fn () => null, $request, HttpKernelInterface::MAIN_REQUEST));

        $hook->after(
            new AiCall(
                capability: AiCapability::ChatStream,
                provider: 'openai',
                model: $secretApiKey,
                context: new AiCallContext(userId: 42, source: AiCallSource::ChatStream, turnId: $secretEmail),
                payload: AiCallPayload::chat([['role' => 'user', 'content' => $secretPrompt]]),
                options: new AiCallOptions(),
            ),
            new AiCallResult(
                value: ['content' => $secretPrompt],
                outcome: AiCallOutcome::Ok,
                usage: new AiUsage(promptTokens: 10, completionTokens: 20, totalTokens: 30, chargedCost: '0.000200'),
                timings: new AiCallTimings(hooksBeforeMs: 0.02, providerMs: 100.0, hooksAfterMs: 0.01, ttftMs: 40.0),
            ),
        );
        $context->recordEgress('https://user:'.$secretApiKey.'@api.openai.com/v1/chat?email='.$secretEmail, 200, 100);

        $recorder->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response($secretPrompt.' '.$secretEmail.' '.$secretApiKey.' '.$secretBearer, 200),
        ));

        $serializedRedisState = json_encode([
            'zset' => $state->zset,
            'hashes' => $state->hashes,
        ], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secretPrompt, $serializedRedisState);
        self::assertStringNotContainsString($secretApiKey, $serializedRedisState);
        self::assertStringNotContainsString($secretBearer, $serializedRedisState);
        self::assertStringNotContainsString('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9', $serializedRedisState);
        self::assertStringNotContainsString($secretEmail, $serializedRedisState);
    }

    public function testRingBufferEnforcesMaxEntriesAndTtl(): void
    {
        [$redis, $state] = $this->createFakeRedis();
        $store = new RequestTimelineStore($redis);
        $context = new RequestTimelineContext();

        $context->start('req-ttl', 'chat_stream', 1, 1730100000, 1_000_000_000);
        $record = $context->buildRecord('web1', '2xx', 1_020_000_000);
        self::assertTrue($store->record($record));

        self::assertSame(RequestTimelineStore::MAX_ENTRIES, $state->lastMaxEntries);
        self::assertSame(RequestTimelineStore::TTL_SECONDS, $state->lastTtlSeconds);
    }

    public function testUsageRowStampsCostOntoTheCallTheHookRecordedAtZero(): void
    {
        $context = new RequestTimelineContext();
        $hook = new AiCallMetricsHook($context);
        $idle = new AiCall(
            capability: AiCapability::Chat,
            provider: 'openai',
            model: 'gpt-4o',
            context: new AiCallContext(userId: 7, source: AiCallSource::ChatWeb),
            payload: AiCallPayload::chat([['role' => 'user', 'content' => 'Hello']]),
            options: new AiCallOptions(),
        );
        $priced = new AiCallResult(
            value: ['content' => 'Hi'],
            outcome: AiCallOutcome::Ok,
            usage: new AiUsage(promptTokens: 10, completionTokens: 4, totalTokens: 14),
            timings: new AiCallTimings(hooksBeforeMs: 0.01, providerMs: 20.0, hooksAfterMs: 0.01),
        );

        $hook->after($idle, $priced);
        self::assertSame([], $context->aiCalls(), 'A call outside a request is not kept on the timeline.');

        $context->start('req-cost', 'chat_stream', 7, 1730100000, 1_000_000_000);
        $hook->after($idle, $priced);
        self::assertSame('0.000000', $context->aiCalls()[0]['cost']);

        $context->stampChargedCost('openai', 'gpt-4o', '0.001250');
        self::assertSame('0.001250', $context->aiCalls()[0]['cost']);

        $context->stampChargedCost('openai', 'gpt-4o', '9.000000');
        self::assertSame('0.001250', $context->aiCalls()[0]['cost']);
    }

    private function wireJsonSerializer(AdminCapacityController $controller): void
    {
        $container = new \Symfony\Component\DependencyInjection\Container();
        $controller->setContainer($container);
    }

    /**
     * @return array{0: RedisService, 1: object{zset: array<string, float>, hashes: array<string, array<string, int>>, lastMaxEntries: int, lastTtlSeconds: int}}
     */
    private function createFakeRedis(): array
    {
        $state = new class {
            /** @var array<string, float> */
            public array $zset = [];
            /** @var array<string, array<string, int>> */
            public array $hashes = [];
            public int $lastMaxEntries = 0;
            public int $lastTtlSeconds = 0;
        };

        $redis = $this->createStub(RedisService::class);
        $redis->method('pipelineTimelineRecord')->willReturnCallback(
            static function (
                string $ringKey,
                float $score,
                string $encodedRecord,
                int $maxEntries,
                int $ttlSeconds,
                array $hashIncrements,
            ) use ($state): bool {
                $state->zset[$encodedRecord] = $score;
                $state->lastMaxEntries = $maxEntries;
                $state->lastTtlSeconds = $ttlSeconds;
                foreach ($hashIncrements as $hashKey => $fields) {
                    foreach ($fields as $field => $delta) {
                        if (0 !== $delta) {
                            $state->hashes[$hashKey][(string) $field] = ($state->hashes[$hashKey][(string) $field] ?? 0) + $delta;
                        }
                    }
                }

                return true;
            },
        );
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('zRevRangeByScore')->willReturnCallback(
            static function () use ($state): array {
                return array_reverse(array_keys($state->zset));
            },
        );

        return [$redis, $state];
    }
}
