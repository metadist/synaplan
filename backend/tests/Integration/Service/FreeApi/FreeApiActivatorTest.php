<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\FreeApi;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\FreeApi\FreeApiActivator;
use App\Service\FreeApi\FreeApiClient;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiInstallFacts;
use App\Service\FreeApi\FreeApiMaintenance;
use App\Service\FreeApi\FreeApiModelBinder;
use App\Service\FreeApi\FreeApiProofOfWork;
use App\Service\FreeApi\FreeApiState;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;

/**
 * Plan P4: nothing is written before the click, and leaving restores the
 * instance-wide model bindings byte for byte. Runs against the test database
 * with the Demo server mocked at the HTTP layer.
 */
final class FreeApiActivatorTest extends KernelTestCase
{
    private const BASE_URL = 'http://127.0.0.1:8787';
    private const KEY = 'sk-free-itest-0001';
    private const MEMBER_ID = 987654;

    private Connection $db;
    private ConfigRepository $config;
    private ModelRepository $models;
    private OpenAiCompatibleEndpointRegistry $endpoints;
    private FreeApiState $state;

    /** @var list<array{method: string, url: string, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $revokeAnswers = [];

    private bool $issueWithoutAliases = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->config = $container->get(ConfigRepository::class);
        $this->models = $container->get(ModelRepository::class);
        $this->endpoints = $container->get(OpenAiCompatibleEndpointRegistry::class);
        $this->state = $container->get(FreeApiState::class);

        foreach ($this->endpoints->listEndpoints() as $endpoint) {
            $this->endpoints->deleteEndpoint($endpoint['name']);
        }
    }

    public function testActivateThenLeaveRestoresEveryBindingByteForByte(): void
    {
        $before = $this->globalBindings();
        $activator = $this->activator();

        $activation = $activator->activate('de');

        $chatModel = $this->demoModel('free-chat', 'chat');
        $this->assertSame(1, $chatModel->getActive());
        $this->assertSame((string) $chatModel->getId(), $this->config->getValue(0, 'DEFAULTMODEL', 'CHAT'));
        $this->assertSame((string) $this->demoModel('free-sort', 'chat')->getId(), $this->config->getValue(0, 'DEFAULTMODEL', 'MEM'));
        $this->assertContains('CHAT', $activation->boundCapabilities);
        $this->assertNotContains('SOUND2TEXT', $activation->boundCapabilities);
        $this->assertSame($activation->vectorizeKept, !\in_array('VECTORIZE', $activation->boundCapabilities, true));
        $this->assertTrue($this->state->isActive());
        $endpoint = $this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME);
        $this->assertNotNull($endpoint);
        $this->assertSame(self::KEY, $endpoint['api_key']);
        $this->assertSame(self::BASE_URL.'/v1', $endpoint['base_url']);
        $this->assertSame($this->state->existingInstallId(), $endpoint['headers']['X-Synaplan-Install'] ?? null);

        $issuance = json_decode($this->requests[0]['body'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('de', $issuance['locale']);
        $this->assertSame('localhost', $issuance['host_kind']);
        $this->assertTrue(FreeApiProofOfWork::verify($issuance['install_id'], $issuance['pow_nonce'], FreeApiProofOfWork::DEFAULT_BITS));

        // A member picked the Demo for themselves while it was active.
        $this->config->setValue(self::MEMBER_ID, 'DEFAULTMODEL', 'CHAT', (string) $chatModel->getId());

        $leave = $activator->leave();

        $this->assertTrue($leave->wasActive);
        $this->assertTrue($leave->keyRevoked);
        $this->assertSame($before, $this->globalBindings());
        $this->assertNull($this->config->getValue(self::MEMBER_ID, 'DEFAULTMODEL', 'CHAT'), 'a member binding to the Demo is dropped');
        $this->assertNull($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME));
        $this->assertFalse($this->state->isActive());
        $this->assertNull($this->state->snapshot());
        $this->assertSame(0, $this->demoModel('free-chat', 'chat')->getActive(), 'rows stay for the usage history, hidden');
        $this->assertSame(0, $this->demoModel('free-chat', 'chat')->getSelectable());
        $this->assertSame('DELETE', end($this->requests)['method']);
    }

    public function testASecondActivationReusesTheRows(): void
    {
        $activator = $this->activator();
        $activator->activate();
        $firstId = $this->demoModel('free-chat', 'chat')->getId();
        $activator->leave();

        $activator->activate();

        $this->assertSame($firstId, $this->demoModel('free-chat', 'chat')->getId());
        $this->assertSame(1, $this->demoModel('free-chat', 'chat')->getActive());
        $this->assertCount(1, $this->models->findBy(['service' => 'OpenAICompatible', 'providerId' => 'free-chat', 'tag' => 'chat']));
    }

    public function testASecondActivationWhileActiveIsRefusedAndKeepsTheSnapshot(): void
    {
        $before = $this->globalBindings();
        $activator = $this->activator();
        $activator->activate();

        try {
            $activator->activate();
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::ALREADY_ACTIVE, $e->errorCode);
        }
        $this->assertCount(1, $this->requests, 'no second key request');

        $activator->leave();
        $this->assertSame($before, $this->globalBindings());
    }

    public function testWorkingChatRefusesTheDemoUnlessForced(): void
    {
        try {
            $this->activator(chatReady: true)->activate();
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::CHAT_READY, $e->errorCode);
        }
        $this->assertSame([], $this->requests);

        $this->activator(chatReady: true)->activate(force: true);
        $this->assertTrue($this->state->isActive());
    }

    public function testAPendingRevokeNeverKillsTheReissuedKey(): void
    {
        $activator = $this->activator();
        $activator->activate();
        $this->revokeAnswers = [new MockResponse('{"error":{"code":"freeapi_paused","message":"paused"}}', ['http_code' => 503])];
        $activator->leave();
        $this->assertSame(self::KEY, $this->state->pendingRevoke());

        // The server hands the unrevoked key out again.
        $activator->activate();
        $requestsBefore = \count($this->requests);

        $maintenance = $activator->maintain();

        $this->assertFalse($maintenance->revokePending);
        $this->assertNull($maintenance->leftBecause);
        $this->assertNull($this->state->pendingRevoke());
        $this->assertCount($requestsBefore, $this->requests, 'no revoke for the key chat runs on');
        $this->assertTrue($this->state->isActive());
    }

    public function testAnExpiredDemoIsLeftByMaintain(): void
    {
        $before = $this->globalBindings();
        $activator = $this->activator();
        $activator->activate();
        $this->config->setValue(0, FreeApiConfig::GROUP, 'EXPIRES_AT', (string) (time() - 1));

        $maintenance = $activator->maintain();

        $this->assertSame(FreeApiMaintenance::LEFT_EXPIRED, $maintenance->leftBecause);
        $this->assertFalse($this->state->isActive());
        $this->assertSame($before, $this->globalBindings());
    }

    public function testASwitchedOffModuleIsLeftByMaintain(): void
    {
        $before = $this->globalBindings();
        $this->activator()->activate();

        $maintenance = $this->activator(enabled: false)->maintain();

        $this->assertSame(FreeApiMaintenance::LEFT_DISABLED, $maintenance->leftBecause);
        $this->assertFalse($this->state->isActive());
        $this->assertSame($before, $this->globalBindings());
        $this->assertNull($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME));
    }

    public function testAPendingRevokePastTheKeyExpiryIsDropped(): void
    {
        $this->state->rememberPendingRevoke(self::KEY, time() - 1);

        $maintenance = $this->activator()->maintain();

        $this->assertFalse($maintenance->revokePending);
        $this->assertNull($this->state->pendingRevoke());
        $this->assertSame([], $this->requests);
    }

    public function testAFailedRetryRestoresBindingsLeftByACrashedInstall(): void
    {
        $before = $this->globalBindings();
        $activator = $this->activator();
        $activator->activate();
        // Crash window: snapshot and Demo bindings are stored, ACTIVE is not.
        $this->state->markInactive();
        $this->issueWithoutAliases = true;

        try {
            $activator->activate();
            $this->fail('an issuance without a chat alias must be refused');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::INVALID_RESPONSE, $e->errorCode);
            $this->assertSame($before, $this->globalBindings());
            $this->assertNull($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME));
            $this->assertFalse($this->state->isActive());
            $this->assertNull($this->state->snapshot());
            $this->assertSame(0, $this->demoModel('free-chat', 'chat')->getActive());
        } finally {
            $this->activator()->leave();
        }
    }

    public function testAnActiveMemoryDoesNotKeepDocumentSearchOffTheDemo(): void
    {
        if (false !== $this->db->fetchOne('SELECT 1 FROM BFILES WHERE BCHUNKCOUNT > 0 LIMIT 1')
            || false !== $this->db->fetchOne('SELECT 1 FROM BRAG LIMIT 1')) {
            $this->markTestSkipped('This database already has a document index.');
        }

        $memoryId = 9900000000001;
        $now = time();
        $this->db->executeStatement(
            'INSERT INTO BUSERMEMORIES (BID, BUSERID, BCATEGORY, BKEY, BVALUE, BSOURCE, BACTIVE, BCREATED, BUPDATED) VALUES (:id, 1, :category, :key, :value, :source, 1, :created, :updated)',
            [
                'id' => $memoryId,
                'category' => 'preferences',
                'key' => 'tone',
                'value' => 'brief',
                'source' => 'user_created',
                'created' => $now,
                'updated' => $now,
            ],
        );

        try {
            $activation = $this->activator()->activate();
            $this->assertContains('VECTORIZE', $activation->boundCapabilities);
            $this->assertFalse($activation->vectorizeKept);
        } finally {
            $this->db->executeStatement('DELETE FROM BUSERMEMORIES WHERE BID = :id', ['id' => $memoryId]);
            $this->activator()->leave();
        }
    }

    public function testAFailedSetupLeavesNothingBehind(): void
    {
        $before = $this->globalBindings();
        $this->issueWithoutAliases = true;

        try {
            $this->activator()->activate();
            $this->fail('an issuance without a chat alias must be refused');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::INVALID_RESPONSE, $e->errorCode);
        }

        $this->assertSame($before, $this->globalBindings());
        $this->assertNull($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME));
        $this->assertFalse($this->state->isActive());
        $this->assertNull($this->state->snapshot());
    }

    public function testAnOwnEndpointBlocksTheDemoWithoutAnyRequest(): void
    {
        $this->endpoints->saveEndpoint('localai', 'http://127.0.0.1:8080/v1', null, [], 'Local AI', ['chat']);

        try {
            $this->activator()->activate();
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::OWN_ENDPOINT, $e->errorCode);
        }
        $this->assertSame([], $this->requests);
    }

    public function testAMissedRevokeIsDeliveredByMaintain(): void
    {
        $activator = $this->activator();
        $activator->activate();
        $this->revokeAnswers = [
            new MockResponse('{"error":{"code":"freeapi_paused","message":"paused"}}', ['http_code' => 503]),
            new MockResponse('{"revoked":true}'),
        ];

        $leave = $activator->leave();

        $this->assertFalse($leave->keyRevoked);
        $this->assertSame(self::KEY, $this->state->pendingRevoke());
        $this->assertNull($this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME), 'the key stops working locally at once');

        $this->assertFalse($activator->maintain()->revokePending);
        $this->assertNull($this->state->pendingRevoke());
        $this->assertSame('DELETE', end($this->requests)['method']);
    }

    public function testMaintainIsSilentWithoutPendingRevoke(): void
    {
        $maintenance = $this->activator()->maintain();

        $this->assertFalse($maintenance->revokePending);
        $this->assertNull($maintenance->leftBecause);
        $this->assertSame([], $this->requests);
    }

    public function testTheModuleSwitchedOffRefusesWithoutAnyRequest(): void
    {
        try {
            $this->activator(enabled: false)->activate();
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::NOT_CONFIGURED, $e->errorCode);
        }
        $this->assertSame([], $this->requests);
        $this->assertNull($this->state->existingInstallId(), 'nothing is written before the click');
    }

    private function activator(bool $enabled = true, bool $chatReady = false): FreeApiActivator
    {
        $container = static::getContainer();
        $chatReadiness = $this->createStub(ChatReadinessService::class);
        $chatReadiness->method('isChatReady')->willReturn($chatReady);
        $config = new FreeApiConfig(
            $this->config,
            new FeatureFlagEnv(['FEATURE_FREEAPI_ENABLED' => $enabled ? 'true' : 'false']),
            self::BASE_URL,
            'test',
            false,
            '4.9.0-itest',
            'selfhost',
        );

        return new FreeApiActivator(
            $config,
            $this->state,
            new FreeApiClient(new MockHttpClient($this->server(...)), $config),
            new FreeApiInstallFacts('http://localhost:8000'),
            $this->endpoints,
            $container->get(FreeApiModelBinder::class),
            $chatReadiness,
            $container->get(LockFactory::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function server(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

        if ('DELETE' === $method) {
            return array_shift($this->revokeAnswers) ?? new MockResponse('{"revoked":true}');
        }

        $aliases = $this->issueWithoutAliases ? [] : [
            ['id' => 'free-chat', 'name' => 'Synaplan Demo · GPT OSS 120B'],
            ['id' => 'free-sort', 'name' => 'Synaplan Demo · GPT OSS 120B'],
            ['id' => 'free-vision', 'name' => 'Synaplan Demo · Qwen 3.8 27B'],
            ['id' => 'free-embed', 'name' => 'Synaplan Demo · bge-m3'],
            ['id' => 'free-image', 'name' => 'Synaplan Demo · Image'],
        ];

        return new MockResponse(json_encode([
            'key' => self::KEY,
            'key_id' => 11,
            'expires_at' => time() + 14 * 86400,
            'days_left' => 14,
            'limits' => ['messages_per_day' => 40, 'images_per_day' => 5],
            'aliases' => $aliases,
            'terms_url' => 'https://web.synaplan.com/demo/terms',
            'base_url' => self::BASE_URL,
            'protocol_min' => 1,
        ], \JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function demoModel(string $alias, string $tag): Model
    {
        $model = $this->models->findOneBy(['service' => 'OpenAICompatible', 'providerId' => $alias, 'tag' => $tag]);
        $this->assertInstanceOf(Model::class, $model);
        static::getContainer()->get('doctrine')->getManager()->refresh($model);

        return $model;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globalBindings(): array
    {
        return $this->db->fetchAllAssociative("SELECT BSETTING, BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = 'DEFAULTMODEL' ORDER BY BSETTING");
    }
}
