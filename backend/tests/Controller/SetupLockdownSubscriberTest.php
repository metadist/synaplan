<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\Setup\SetupStateService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API lockdown that holds while an installation still needs its first-run
 * setup.
 *
 * Two halves matter equally. On a virgin instance everything except the wizard's
 * own endpoints has to be shut — the webhooks above all, because they create
 * BUSERLEVEL='ANONYMOUS' rows and a single planted row would strand the install
 * with no way to create an administrator. On every EXISTING instance the
 * lockdown must be invisible, which is the half that would take a production
 * deployment offline if it regressed.
 */
final class SetupLockdownSubscriberTest extends WebTestCase
{
    use EnablesSetupWizard;

    protected function tearDown(): void
    {
        $this->restoreSetupWizardEnv();

        parent::tearDown();
    }

    public function testAGuardedApiPathIsShutWhileSetupIsRequired(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('GET', '/api/v1/chats');

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        $payload = $this->payload($client);
        self::assertSame('SETUP_REQUIRED', $payload['code']);
        self::assertSame('/setup', $payload['setupUrl']);
    }

    /**
     * The reason the lockdown exists at all: this endpoint is PUBLIC_ACCESS and
     * creates a user row, so leaving it open would let a stranger permanently
     * block the wizard.
     */
    public function testThePublicEmailWebhookIsShutWhileSetupIsRequired(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request(
            'POST',
            '/api/v1/webhooks/email',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}'
        );

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('SETUP_REQUIRED', $this->payload($client)['code']);
    }

    public function testTheHealthProbeStaysReachable(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('GET', '/api/health');

        self::assertNotSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $client->getResponse()->getStatusCode(),
            'an orchestrator must not see a fresh container as unhealthy'
        );
    }

    /**
     * The SPA cannot learn that it should go to /setup any other way, so this is
     * the one endpoint whose availability the whole flow depends on.
     */
    public function testTheRuntimeConfigStaysReachableAndAnnouncesTheWizard(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('GET', '/api/v1/config/runtime');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['setup']['wizardRequired']);
    }

    public function testTheSetupEndpointsStayReachable(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('GET', '/api/v1/setup/state');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['wizardRequired']);
    }

    /**
     * A 503 on the preflight turns every cross-origin call into an opaque
     * browser error instead of the readable 503 the real request gets.
     */
    public function testCorsPreflightIsNotShut(): void
    {
        $client = $this->clientOnAVirginInstance();

        $client->request('OPTIONS', '/api/v1/chats');

        self::assertNotSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $client->getResponse()->getStatusCode()
        );
    }

    /**
     * The regression that would be most expensive in production: on an
     * installation that has users the lockdown must not engage at all. An
     * unauthenticated call gets the usual 401 from the firewall, not a 503.
     */
    public function testAnInstallationWithUsersIsNotAffected(): void
    {
        $client = $this->clientWithSetupState(userCount: 1, completedFlag: null);

        $client->request('GET', '/api/v1/chats');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheRuntimeConfigOfAnInstallationWithUsersReportsNoWizard(): void
    {
        $client = $this->clientWithSetupState(userCount: 1, completedFlag: null);

        $client->request('GET', '/api/v1/config/runtime');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['setup']['wizardRequired']);
    }

    /**
     * The completed flag alone has to be enough — that is what the backfill
     * migration writes on an upgrade whose user table it cannot see.
     */
    public function testTheCompletedFlagAloneLiftsTheLockdown(): void
    {
        $client = $this->clientWithSetupState(userCount: 0, completedFlag: '1');

        $client->request('GET', '/api/v1/chats');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * The operator kill switch. It is also what keeps the rest of this test suite
     * green against the fixture-less PHPUnit database, so a regression here would
     * be loud.
     */
    public function testTheKillSwitchLiftsTheLockdownOnAVirginInstance(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->disableSetupWizard();
        $this->replaceSetupState(userCount: 0, completedFlag: null);

        $client->request('GET', '/api/v1/chats');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function clientOnAVirginInstance(): KernelBrowser
    {
        return $this->clientWithSetupState(userCount: 0, completedFlag: null);
    }

    /**
     * Puts a chosen view of the installation into the container, with the wizard
     * switched on for the duration of the test.
     *
     * A real {@see SetupStateService} over stubbed repositories rather than a mock
     * of the service: it is final, and its decision logic is exactly what should
     * run here.
     */
    private function clientWithSetupState(int $userCount, ?string $completedFlag): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->enableSetupWizard();
        $this->replaceSetupState($userCount, $completedFlag);

        return $client;
    }

    private function replaceSetupState(int $userCount, ?string $completedFlag): void
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn($completedFlag);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('countAll')->willReturn($userCount);
        $userRepository->method('hasAdmin')->willReturn($userCount > 0);

        static::getContainer()->set(
            SetupStateService::class,
            new SetupStateService($configRepository, $userRepository),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
