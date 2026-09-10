<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config;

use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\User;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\ModuleRegistry;
use App\Module\ModuleStatusPresenter;
use App\Module\Sidecar\DoclingModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Module\Sidecar\TikaModule;
use App\Plug\WebSearch\WebSearchGateway;
use App\Repository\ModelRepository;
use App\Service\Config\FeatureStatusReporter;
use App\Service\Infrastructure\RedisService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use App\Service\WhisperService;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use App\Tests\Unit\Module\Fixture\FakeSidecarHealthProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Characterization of the admin feature-status payload.
 *
 * Two fully faked environments — nothing configured / everything configured
 * and healthy — are rendered and compared byte-for-byte with the committed
 * snapshots. The payload is a frontend contract (StatusView), so any diff here
 * is either a regression or a deliberate contract change that must be reviewed
 * line by line.
 *
 * Record/refresh with: UPDATE_FEATURE_STATUS_SNAPSHOTS=1
 */
final class FeatureStatusReporterTest extends TestCase
{
    use BuildsAllModules;

    private const SNAPSHOT_DIR = __DIR__.'/__snapshots__';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key.'='.$value);
            }
        }
        $this->envBackup = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarios(): iterable
    {
        yield 'bare' => ['bare'];
        yield 'full' => ['full'];
    }

    #[DataProvider('scenarios')]
    public function testPayloadMatchesSnapshot(string $scenario): void
    {
        $reporter = 'bare' === $scenario ? $this->bareReporter() : $this->fullReporter();

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $actual = self::encode($reporter->build($user));
        $file = self::SNAPSHOT_DIR.'/features_status.'.$scenario.'.json';

        if ('1' === (getenv('UPDATE_FEATURE_STATUS_SNAPSHOTS') ?: '')) {
            if (!is_dir(self::SNAPSHOT_DIR)) {
                mkdir(self::SNAPSHOT_DIR, 0o777, true);
            }
            file_put_contents($file, $actual."\n");
            $this->assertFileExists($file, 'Recorded feature-status baseline.');

            return;
        }

        $this->assertFileExists($file, 'Missing feature-status baseline. Generate it once with UPDATE_FEATURE_STATUS_SNAPSHOTS=1 and commit '.$file);
        $this->assertSame(rtrim((string) file_get_contents($file), "\n"), $actual, "Feature-status payload drifted from the committed snapshot ({$scenario}).");
    }

    public function testSummaryCountsFollowTheFeatureRows(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $payload = $this->fullReporter()->build($user);

        $this->assertSame(count($payload['features']), $payload['summary']['total']);
        $this->assertSame($payload['summary']['total'], $payload['summary']['healthy']);
        $this->assertSame(0, $payload['summary']['unhealthy']);
        $this->assertTrue($payload['summary']['all_ready']);

        $bare = $this->bareReporter()->build($user);
        $this->assertFalse($bare['summary']['all_ready']);
        $this->assertGreaterThan(0, $bare['summary']['unhealthy']);
    }

    public function testTheSyntheticTestProviderIsHiddenOutsideDev(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $features = $this->bareReporter()->build($user)['features'];

        $this->assertArrayNotHasKey('test', $features);
        $this->assertArrayHasKey('openai', $features);
        $this->assertArrayHasKey('ollama', $features);
    }

    public function testProviderModelCountsUseTheServiceColumnAndNormalizeCasing(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $features = $this->fullReporter()->build($user)['features'];

        $this->assertSame(3, $features['openai']['models_available']);
        $this->assertSame(1, $features['ollama']['models_available']);
    }

    private function bareReporter(): FeatureStatusReporter
    {
        $this->setEnv('APP_ENV', 'test');
        $this->setEnv('BRAVE_SEARCH_API_KEY', '');
        $this->setEnv('BRAVE_SEARCH_ENABLED', 'false');
        $this->setEnv('OPENAI_API_KEY', '');
        $this->setEnv('OLLAMA_BASE_URL', 'http://ollama:11434');
        $this->setEnv('TIKA_BASE_URL', 'http://tika:9998');
        $this->setEnv('TIKA_HTTP_USER', '');
        $this->setEnv('TIKA_HTTP_PASS', '');
        $this->setEnv('DOCLING_BASE_URL', '');
        $this->setEnv('OFFICE_CONVERT_URL', 'disabled');
        $this->setEnv('QDRANT_URL', '');
        $this->setEnv('REDIS_DSN', '');
        $this->setEnv('REALTIME_ENABLED', 'false');
        $this->setEnv('REALTIME_API_URL', '');

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $models = $this->createStub(ModelRepository::class);
        $models->method('findBy')->willReturn([]);
        $models->method('findAllActive')->willReturn([]);

        $providers = $this->createStub(ProviderRegistry::class);
        $providers->method('getProvidersMetadata')->willReturn([
            'openai' => self::provider('OpenAI', 'GPT models', false, 'unhealthy', 'API key not configured', ['OPENAI_API_KEY' => 'From platform.openai.com']),
            'ollama' => self::provider('Ollama', 'Local models', true, 'unhealthy', 'Connection refused', ['OLLAMA_BASE_URL' => 'Ollama server URL']),
            'test' => self::provider('Test', 'Synthetic', true, 'healthy', null, []),
        ]);

        $webSearch = $this->createStub(WebSearchGateway::class);
        $webSearch->method('isEnabled')->willReturn(false);

        $whisper = $this->createStub(WhisperService::class);
        $whisper->method('isAvailable')->willReturn(false);
        $whisper->method('getAvailableModels')->willReturn([]);

        $memory = $this->createStub(UserMemoryService::class);
        $memory->method('isAvailable')->willReturn(false);

        $redis = $this->createStub(RedisService::class);
        $redis->method('ping')->willReturn(false);
        $redis->method('getLastConnectionError')->willReturn(new \RuntimeException('Connection refused [tcp://redis:6379]'));
        $redis->method('serverVersion')->willReturn(null);

        $probe = new FakeSidecarHealthProbe();
        $registry = $this->registry($probe, tikaUrl: 'http://tika:9998', doclingUrl: '', officeUrl: 'disabled');

        return new FeatureStatusReporter($connection, $models, $providers, $webSearch, $whisper, $memory, $redis, $probe, $registry, new ModuleStatusPresenter($registry));
    }

    private function fullReporter(): FeatureStatusReporter
    {
        $this->setEnv('APP_ENV', 'test');
        $this->setEnv('BRAVE_SEARCH_API_KEY', 'BSA-secret');
        $this->setEnv('BRAVE_SEARCH_ENABLED', 'true');
        $this->setEnv('OPENAI_API_KEY', 'sk-secret');
        $this->setEnv('OLLAMA_BASE_URL', 'http://ollama:11434');
        $this->setEnv('TIKA_BASE_URL', 'http://tika:9998');
        $this->setEnv('TIKA_HTTP_USER', 'tika');
        $this->setEnv('TIKA_HTTP_PASS', 'secret');
        $this->setEnv('DOCLING_BASE_URL', ' http://docling:5001/ ');
        $this->setEnv('OFFICE_CONVERT_URL', ' http://collabora:9980 ');
        $this->setEnv('QDRANT_URL', 'http://qdrant:6333');
        $this->setEnv('REDIS_DSN', 'redis://user:pass@redis:6379');
        $this->setEnv('REALTIME_ENABLED', 'true');
        $this->setEnv('REALTIME_API_URL', 'http://centrifugo:8000/api/');

        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn('11.4.2-MariaDB-ubu2404');
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        $models = $this->createStub(ModelRepository::class);
        $models->method('findBy')->willReturnCallback(static function (array $criteria): array {
            if (($criteria['tag'] ?? null) === 'TEXT2PIC') {
                return [new \stdClass(), new \stdClass()];
            }

            return [];
        });
        // Catalog casing ('OpenAI') must still count toward the registry key ('openai').
        $models->method('findAllActive')->willReturn([
            self::activeModel('OpenAI'),
            self::activeModel('OpenAI'),
            self::activeModel('OpenAI'),
            self::activeModel('Ollama'),
        ]);

        $providers = $this->createStub(ProviderRegistry::class);
        $providers->method('getProvidersMetadata')->willReturn([
            'openai' => self::provider('OpenAI', 'GPT models', true, 'healthy', null, ['OPENAI_API_KEY' => 'From platform.openai.com']),
            'ollama' => self::provider('Ollama', 'Local models', true, 'healthy', null, ['OLLAMA_BASE_URL' => 'Ollama server URL']),
        ]);

        $webSearch = $this->createStub(WebSearchGateway::class);
        $webSearch->method('isEnabled')->willReturn(true);

        $whisper = $this->createStub(WhisperService::class);
        $whisper->method('isAvailable')->willReturn(true);
        $whisper->method('getAvailableModels')->willReturn(['base', 'small']);

        $qdrant = $this->createStub(QdrantClientInterface::class);
        $qdrant->method('getHealthDetails')->willReturn(['version' => '1.11.0', 'qdrant' => ['collections' => 2, 'points' => 1234]]);
        $memory = $this->createStub(UserMemoryService::class);
        $memory->method('isAvailable')->willReturn(true);
        $memory->method('getQdrantClient')->willReturn($qdrant);

        $redis = $this->createStub(RedisService::class);
        $redis->method('ping')->willReturn(true);
        $redis->method('getLastConnectionError')->willReturn(null);
        $redis->method('serverVersion')->willReturn('7.2.4');

        $probe = new FakeSidecarHealthProbe(
            reachable: [
                'http://tika:9998/tika' => true,
                'http://docling:5001/health' => true,
                'http://collabora:9980/hosting/capabilities' => true,
                'http://centrifugo:8000/health' => true,
            ],
            bodies: ['http://tika:9998/version' => "Apache Tika 2.9.2\n"],
        );
        $registry = $this->registry($probe, tikaUrl: 'http://tika:9998', doclingUrl: ' http://docling:5001/ ', officeUrl: ' http://collabora:9980 ', tikaUser: 'tika', tikaPass: 'secret');

        return new FeatureStatusReporter($connection, $models, $providers, $webSearch, $whisper, $memory, $redis, $probe, $registry, new ModuleStatusPresenter($registry));
    }

    /**
     * All twelve descriptors (unconfigured stubs), with the three sidecars the
     * page renders replaced by instances that see the scenario's URLs.
     */
    private function registry(FakeSidecarHealthProbe $probe, string $tikaUrl, string $doclingUrl, string $officeUrl, ?string $tikaUser = null, ?string $tikaPass = null): ModuleRegistry
    {
        $modules = $this->allModules();
        $modules[TikaModule::ID] = new TikaModule($probe, $tikaUrl, $tikaUser, $tikaPass);
        $modules[DoclingModule::ID] = new DoclingModule($probe, $doclingUrl);
        $modules[OfficeConvertModule::ID] = new OfficeConvertModule($probe, $officeUrl);

        $factories = [];
        foreach ($modules as $id => $module) {
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }

        return new ModuleRegistry(new ServiceLocator($factories));
    }

    private static function activeModel(string $service): Model
    {
        return (new Model())->setService($service);
    }

    /**
     * @param array<string, string> $envHints var => hint
     *
     * @return array<string, mixed>
     */
    private static function provider(string $name, string $description, bool $enabled, string $status, ?string $error, array $envHints): array
    {
        $envVars = [];
        foreach ($envHints as $var => $hint) {
            $envVars[$var] = ['required' => true, 'hint' => $hint];
        }

        return [
            'id' => strtolower($name),
            'name' => $name,
            'description' => $description,
            'capabilities' => [],
            'enabled' => $enabled,
            'status' => $status,
            'status_message' => $error ?? $description,
            'setup_required' => !$enabled,
            'env_vars' => $envVars,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function setEnv(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->envBackup)) {
            $existing = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            $this->envBackup[$key] = is_string($existing) ? $existing : false;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
