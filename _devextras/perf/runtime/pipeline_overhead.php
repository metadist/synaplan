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
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Infrastructure\CircuitBreaker;
use App\Service\Usage\UsageFailureLogger;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

$pipeline = new AiCallPipeline([
    new RequestContextHook(),
    new CircuitBreakerHook($breaker),
    new ModelHealthHook($health),
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

// Warm up JIT / opcache / memoized reflection.
for ($i = 0; $i < 25; ++$i) {
    $pipeline->run($call, $noop);
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

echo json_encode([
    'calls' => $calls,
    'p50_ms' => round($samplesMs[$p50Index], 4),
    'p95_ms' => round($samplesMs[$p95Index], 4),
    'p99_ms' => round($samplesMs[$p99Index], 4),
    'max_ms' => round($samplesMs[$calls - 1], 4),
], JSON_THROW_ON_ERROR)."\n";
