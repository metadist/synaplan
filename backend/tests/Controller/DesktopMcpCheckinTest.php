<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Chat;
use App\Entity\DesktopDevice;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end coverage of the desktop check-in loop over the real MCP endpoint:
 * a scoped key + device sees the two agent tools (flag on), leases an enqueued
 * job, reports a result, and — critically — a normal key or a flag-off instance
 * does NOT see the tools (invariants C2 + C8).
 */
final class DesktopMcpCheckinTest extends WebTestCase
{
    private const PROTOCOL_VERSION = '2025-11-25';
    private const DESKTOP_KEY = 'sk_desktop_checkin_test_key_00000000000001';
    private const PLAIN_KEY = 'sk_plain_mcp_test_key_00000000000000000002';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, DesktopAgentConfig::CONFIG_GROUP, DesktopAgentConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    public function testDesktopToolsPresentForPairedDeviceWhenFlagOn(): void
    {
        $this->client->disableReboot();
        $this->enableFlag();
        $this->createUserWithDesktopKey('desktop-mcp-a@synaplan.internal', self::DESKTOP_KEY);

        $tools = $this->toolNames(self::DESKTOP_KEY);

        self::assertContains('agent_checkin', $tools);
        self::assertContains('agent_report_result', $tools);
        // Superset, not a replacement (C2): the base tools stay.
        self::assertContains('synaplan_chat', $tools);
        self::assertContains('rag_search', $tools);
    }

    public function testDesktopToolsAbsentForPlainKeyEvenWhenFlagOn(): void
    {
        $this->client->disableReboot();
        $this->enableFlag();
        $this->createUserWithApiKey('desktop-mcp-plain@synaplan.internal', self::PLAIN_KEY, scoped: false, withDevice: false);

        $tools = $this->toolNames(self::PLAIN_KEY);

        self::assertNotContains('agent_checkin', $tools);
        self::assertNotContains('agent_report_result', $tools);
        self::assertContains('synaplan_chat', $tools);
    }

    public function testDesktopToolsAbsentWhenFlagOff(): void
    {
        $this->client->disableReboot();
        // Flag stays off (no enableFlag()).
        $this->createUserWithDesktopKey('desktop-mcp-off@synaplan.internal', self::DESKTOP_KEY);

        $tools = $this->toolNames(self::DESKTOP_KEY);

        self::assertNotContains('agent_checkin', $tools);
        self::assertNotContains('agent_report_result', $tools);
    }

    public function testCheckinLeasesEnqueuedJobAndReportPostsCompletion(): void
    {
        $this->client->disableReboot();
        $this->enableFlag();
        [$user, $device] = $this->createUserWithDesktopKey('desktop-mcp-loop@synaplan.internal', self::DESKTOP_KEY);

        $chat = (new Chat())->setUserId((int) $user->getId())->setSource('web')->setTitle('Desktop job');
        $this->em->persist($chat);
        $this->em->flush();

        $store = static::getContainer()->get(DesktopJobStore::class);
        $job = $store->enqueueSkillRun((int) $user->getId(), (int) $device->getId(), 'hello-files', 'do it', [], (int) $chat->getId());

        $sessionId = $this->initialize(self::DESKTOP_KEY);

        // First check-in leases the job.
        $checkin = $this->callTool($sessionId, 'agent_checkin', ['protocol' => 1, 'capabilities' => ['skill.run'], 'enabledSkills' => ['hello-files']], 10);
        $sc = $checkin['result']['structuredContent'] ?? null;
        self::assertIsArray($sc, json_encode($checkin));
        self::assertSame(1, $sc['protocol']);
        self::assertCount(1, $sc['jobs']);
        self::assertSame((int) $job->getId(), $sc['jobs'][0]['jobId']);
        self::assertSame(['skill', 'prompt', 'fileIds'], array_keys($sc['jobs'][0]['input']));
        $leaseToken = $sc['jobs'][0]['leaseToken'];
        self::assertNotEmpty($leaseToken);

        // Second check-in while leased → no jobs.
        $checkin2 = $this->callTool($sessionId, 'agent_checkin', ['protocol' => 1], 11);
        self::assertCount(0, $checkin2['result']['structuredContent']['jobs']);

        // Report success.
        $report = $this->callTool($sessionId, 'agent_report_result', [
            'leaseToken' => $leaseToken,
            'status' => 'succeeded',
            'result' => ['summary' => 'made it', 'fileIds' => [123]],
        ], 12);
        $rsc = $report['result']['structuredContent'] ?? null;
        self::assertIsArray($rsc, json_encode($report));
        self::assertTrue($rsc['success']);
        self::assertSame('succeeded', $rsc['status']);

        // A completion note was posted into the chat.
        $messages = $this->em->getRepository(\App\Entity\Message::class)
            ->findBy(['chatId' => (int) $chat->getId(), 'direction' => 'OUT']);
        self::assertNotEmpty($messages, 'a completion note must be posted into the originating chat');
    }

    public function testReportWithWrongLeaseTokenIsToolError(): void
    {
        $this->client->disableReboot();
        $this->enableFlag();
        $this->createUserWithDesktopKey('desktop-mcp-badtoken@synaplan.internal', self::DESKTOP_KEY);

        $sessionId = $this->initialize(self::DESKTOP_KEY);
        $report = $this->callTool($sessionId, 'agent_report_result', [
            'leaseToken' => 'lt_does_not_exist',
            'status' => 'succeeded',
        ], 20);

        self::assertTrue($report['result']['isError'] ?? false, json_encode($report));
    }

    public function testUnknownProtocolReturnsNoJobsAndFarNextCall(): void
    {
        $this->client->disableReboot();
        $this->enableFlag();
        [$user, $device] = $this->createUserWithDesktopKey('desktop-mcp-proto@synaplan.internal', self::DESKTOP_KEY);

        $store = static::getContainer()->get(DesktopJobStore::class);
        $store->enqueueSkillRun((int) $user->getId(), (int) $device->getId(), 'hello-files', 'x');

        $sessionId = $this->initialize(self::DESKTOP_KEY);
        $checkin = $this->callTool($sessionId, 'agent_checkin', ['protocol' => 999], 30);
        $sc = $checkin['result']['structuredContent'];

        self::assertSame(1, $sc['protocol']);
        self::assertCount(0, $sc['jobs'], 'an unknown protocol must never be handed work');
        self::assertGreaterThan(time() + 600, $sc['next_call_at'], 'unknown protocol should defer far into the future');
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $apiKey): array
    {
        $sessionId = $this->initialize($apiKey);
        $result = $this->jsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ], $sessionId, $apiKey);

        self::assertArrayHasKey('result', $result, json_encode($result));

        return array_map(static fn (array $t): string => $t['name'], $result['result']['tools']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(string $sessionId, string $name, array $arguments, int $id): array
    {
        return $this->jsonRpc([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments],
        ], $sessionId, self::DESKTOP_KEY);
    }

    private function initialize(string $apiKey): string
    {
        $result = $this->jsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit-desktop', 'version' => '1.0.0'],
            ],
        ], null, $apiKey);

        self::assertArrayHasKey('result', $result, json_encode($result));

        return (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function jsonRpc(array $payload, ?string $sessionId, string $apiKey): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_X_API_KEY' => $apiKey,
            'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION,
        ];
        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->client->request('POST', '/mcp', server: $server, content: (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'MCP response was not valid JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    /**
     * @return array{0: User, 1: DesktopDevice}
     */
    private function createUserWithDesktopKey(string $email, string $key): array
    {
        $user = $this->createUserWithApiKey($email, $key, scoped: true, withDevice: true);
        $device = $this->em->getRepository(DesktopDevice::class)->findOneBy(['ownerId' => (int) $user->getId()]);
        self::assertInstanceOf(DesktopDevice::class, $device);

        return [$user, $device];
    }

    private function createUserWithApiKey(string $email, string $key, bool $scoped, bool $withDevice): User
    {
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => $email]);
        if (null === $user) {
            $user = (new User())
                ->setMail($email)
                ->setType('WEB')
                ->setProviderId('desktop-mcp-test-'.uniqid())
                ->setUserLevel('NEW');
            $user->setCreated(date('YmdHis'));
            $user->setEmailVerified(true);
            $this->em->persist($user);
            $this->em->flush();
        }

        $apiKey = (new ApiKey())
            ->setOwner($user)
            ->setKey($key)
            ->setStatus('active')
            ->setName('Desktop MCP Test')
            ->setScopes($scoped ? ApiKeyScope::pairingScopes() : []);
        $this->em->persist($apiKey);
        $this->em->flush();

        if ($withDevice) {
            $device = (new DesktopDevice())
                ->setOwnerId((int) $user->getId())
                ->setName('Test laptop')
                ->setApiKeyId((int) $apiKey->getId())
                ->setStatus(DesktopDevice::STATUS_ACTIVE)
                ->setCapabilities(['skill.run']);
            $this->em->persist($device);
            $this->em->flush();
        }

        return $user;
    }
}
