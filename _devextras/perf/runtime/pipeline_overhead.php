<?php

declare(strict_types=1);

require '/var/www/backend/vendor/autoload.php';

use App\AI\Health\FailureClassifier;
use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\Hook\AiCallMetricsHook;
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\EventSubscriber\RequestTimelineRecorder;
use App\Observability\RequestIdGenerator;
use App\Observability\RequestTimelineContext;
use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Infrastructure\CircuitBreaker;
use App\Service\Infrastructure\RedisService;
use App\Service\Usage\UsageFailureLogger;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$calls = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (int) $argv[1]) : 1000;

$cache = new ArrayAdapter();
$logger = new NullLogger();
$breaker = new CircuitBreaker($cache, $logger);

$refConfig = new ReflectionClass(ConfigRepository::class);
/** @var ConfigRepository $configRepo */
$configRepo = $refConfig->newInstanceWithoutConstructor();
$healthConfig = new ModelHealthConfig($configRepo);

$refModelRepo = new ReflectionClass(ModelRepository::class);
/** @var ModelRepository $modelRepo */
$modelRepo = $refModelRepo->newInstanceWithoutConstructor();

$refConn = new ReflectionClass(Connection::class);
/** @var Connection $conn */
$conn = $refConn->newInstanceWithoutConstructor();
$failureLogger = new UsageFailureLogger($conn, $logger);

$health = new ModelHealthRecorder(
    $cache,
    $healthConfig,
    new FailureClassifier(),
    $modelRepo,
    $failureLogger,
    $logger,
);

// Prime ModelHealthRecorder's catalog memo so every benchmarked call executes
// the real cache read-modify-write window update for model id 1.
$refMemo = new ReflectionProperty(ModelHealthRecorder::class, 'resolveMemo');
$refMemo->setValue($health, ['chat|test|test-model' => 1]);

$timelineContext = new RequestTimelineContext();

$pipeline = new AiCallPipeline([
    new RequestContextHook(),
    new CircuitBreakerHook($breaker),
    new ModelHealthHook($health),
    new AiCallMetricsHook($timelineContext),
], $logger);

$call = new AiCall(
    capability: AiCapability::Chat,
    provider: 'test',
    model: 'test-model',
    context: new AiCallContext(userId: 1, source: AiCallSource::ChatWeb),
    payload: AiCallPayload::chat([['role' => 'user', 'content' => 'Hello']]),
    options: new AiCallOptions(),
);

$noop = static fn (AiCall $c): array => [
    'content' => 'ok',
    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6],
];

// Warm up JIT / opcache and verify that the health counter actually increments.
for ($i = 0; $i < 25; ++$i) {
    $pipeline->run($call, $noop);
}

$warmupSnapshot = $health->snapshot(1);
if ($warmupSnapshot->successes < 25) {
    fwrite(STDERR, "pipeline_overhead: ModelHealthRecorder did not record warmup successes\n");
    exit(1);
}

$samplesMs = [];
for ($i = 0; $i < $calls; ++$i) {
    $start = hrtime(true);
    $pipeline->run($call, $noop);
    $samplesMs[] = (hrtime(true) - $start) / 1_000_000.0;
}

sort($samplesMs);
$p50Index = (int) floor(($calls - 1) * 0.50);
$p95Index = (int) floor(($calls - 1) * 0.95);
$p99Index = (int) floor(($calls - 1) * 0.99);

// Also measure RequestTimelineRecorder + RequestTimelineStore overhead against Redis
$redisDsn = getenv('REDIS_DSN') ?: 'redis://redis:6379';
$redis = new RedisService($redisDsn, 'perf', $logger);
$timelineStore = new RequestTimelineStore($redis);
$recorder = new RequestTimelineRecorder($timelineContext, $timelineStore, new ServerIdentity('perf'));
$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response('ok', 200);
    }
};

$req = Request::create('/api/v1/messages/stream', 'POST');
$req->attributes->set('_route', 'api_messages_stream');
$req->attributes->set(RequestIdGenerator::ATTRIBUTE, 'perf-req');
$resp = new Response('ok', 200);

$timelineSamplesMs = [];
$timelineRuns = min(200, $calls);
for ($i = 0; $i < $timelineRuns; ++$i) {
    $tStart = hrtime(true);
    $recorder->onKernelRequest(new RequestEvent($kernel, $req, HttpKernelInterface::MAIN_REQUEST));
    $recorder->onKernelController(new ControllerEvent($kernel, static fn () => null, $req, HttpKernelInterface::MAIN_REQUEST));
    $timelineContext->recordDbQuery(1.2);
    $recorder->onKernelTerminate(new TerminateEvent($kernel, $req, $resp));
    $timelineSamplesMs[] = (hrtime(true) - $tStart) / 1_000_000.0;
}
sort($timelineSamplesMs);
$tP95Index = (int) floor(($timelineRuns - 1) * 0.95);

echo json_encode([
    'calls' => $calls,
    'p50_ms' => round($samplesMs[$p50Index], 4),
    'p95_ms' => round($samplesMs[$p95Index], 4),
    'p99_ms' => round($samplesMs[$p99Index], 4),
    'max_ms' => round($samplesMs[$calls - 1], 4),
    'timeline_p95_ms' => round($timelineSamplesMs[$tP95Index], 4),
], JSON_THROW_ON_ERROR)."\n";
