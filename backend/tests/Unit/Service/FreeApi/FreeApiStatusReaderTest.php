<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\FreeApi\FreeApiClient;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiException;
use App\Service\FreeApi\FreeApiState;
use App\Service\FreeApi\FreeApiStatusReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class FreeApiStatusReaderTest extends TestCase
{
    private const NOW = 1760659200;
    private const INSTALL_ID = '9f3c2a10-5b7e-4c1d-8a2f-0e6b4d3c2a19';

    public function testAnInactiveDemoAsksTheServerNothing(): void
    {
        $client = $this->createMock(FreeApiClient::class);
        $client->expects(self::never())->method('status');

        $status = $this->reader($this->state(false), $client)->read(true, self::NOW);

        self::assertTrue($status['available']);
        self::assertFalse($status['active']);
        self::assertNull($status['expiresAt']);
        self::assertNull($status['daysLeft']);
        self::assertNull($status['today']);
        self::assertSame(['messagesPerDay' => null, 'imagesPerDay' => null], $status['limits']);
    }

    public function testAnActiveDemoReportsTodayAndTheDaysLeft(): void
    {
        $client = $this->createStub(FreeApiClient::class);
        $client->method('status')->willReturn([
            'messages_remaining' => 27, 'messages_limit' => 40,
            'images_remaining' => 3, 'images_limit' => 5, 'resets_at' => self::NOW + 3600,
        ]);

        $status = $this->reader($this->state(true), $client)->read(false, self::NOW);

        self::assertTrue($status['active']);
        self::assertSame(12, $status['daysLeft']);
        self::assertSame(['messagesPerDay' => 40, 'imagesPerDay' => 5], $status['limits']);
        self::assertSame(['messagesRemaining' => 27, 'messagesLimit' => 40, 'imagesRemaining' => 3, 'imagesLimit' => 5, 'resetsAt' => self::NOW + 3600], $status['today']);
        self::assertNull($status['refusal']);
    }

    public function testWhoStartedItAndTheInstallIdAreForAdminsOnly(): void
    {
        $reader = $this->reader($this->state(true), $this->createStub(FreeApiClient::class));

        $member = $reader->read(false, self::NOW);
        $admin = $reader->read(true, self::NOW);

        self::assertFalse($member['canManage']);
        self::assertNull($member['activatedBy']);
        self::assertNull($member['installId']);
        self::assertTrue($admin['canManage']);
        self::assertSame('admin@synaplan.com', $admin['activatedBy']);
        self::assertSame(self::INSTALL_ID, $admin['installId']);
    }

    public function testTheServerIsAskedOnceAMinuteAndARefusalIsPassedOn(): void
    {
        $client = $this->createMock(FreeApiClient::class);
        $client->expects(self::once())->method('status')
            ->willThrowException(new FreeApiException('paused', 'freeapi_paused', 503));
        $reader = $this->reader($this->state(true), $client);

        $first = $reader->read(false, self::NOW);
        $second = $reader->read(false, self::NOW + 5);

        self::assertSame('freeapi_paused', $first['refusal']);
        self::assertSame('freeapi_paused', $second['refusal']);
        self::assertNull($second['today']);
    }

    public function testForgetMakesTheNextReadAskAgain(): void
    {
        $client = $this->createMock(FreeApiClient::class);
        $client->expects(self::exactly(2))->method('status')->willReturn(['messages_remaining' => 1, 'messages_limit' => 40]);
        $reader = $this->reader($this->state(true), $client);

        $reader->read(false, self::NOW);
        $reader->forget();
        $reader->read(false, self::NOW);
    }

    public function testTheResponseNeverCarriesTheKey(): void
    {
        $client = $this->createStub(FreeApiClient::class);
        $client->method('status')->willReturn(['messages_remaining' => 1, 'messages_limit' => 40, 'key' => 'sk-free-secret']);

        $encoded = json_encode($this->reader($this->state(true), $client)->read(true, self::NOW), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('sk-free-secret', $encoded);
    }

    private function state(bool $active): FreeApiState
    {
        $state = $this->createStub(FreeApiState::class);
        $state->method('isActive')->willReturn($active);
        $state->method('expiresAt')->willReturn(self::NOW + 11 * 86400 + 600);
        $state->method('activatedAt')->willReturn(self::NOW - 2 * 86400);
        $state->method('activatedBy')->willReturn(1);
        $state->method('existingInstallId')->willReturn(self::INSTALL_ID);
        $state->method('limits')->willReturn(['messages_per_day' => 40, 'images_per_day' => 5, 'cost_per_day_usd' => 0.2]);

        return $state;
    }

    private function reader(FreeApiState $state, FreeApiClient $client): FreeApiStatusReader
    {
        $config = $this->createStub(FreeApiConfig::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('serverHost')->willReturn('web.synaplan.com');
        $endpoints = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $endpoints->method('getEndpoint')->willReturn(['api_key' => 'sk-free-secret']);
        $admin = $this->createStub(User::class);
        $admin->method('getMail')->willReturn('admin@synaplan.com');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($admin);

        return new FreeApiStatusReader($config, $state, $client, $endpoints, $users, new ArrayAdapter());
    }
}
