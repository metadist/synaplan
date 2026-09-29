<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\TelegramBotRepository;
use App\Service\Credential\CredentialVaultInterface;
use App\Service\Telegram\PublicWebhookUrlValidator;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramBotIdentity;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramConnectionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class TelegramConnectionServiceTest extends TestCase
{
    private const TOKEN = '123456789:AAHexampleToken';

    public function testConnectRejectsALocalInstallBeforeCallingTelegram(): void
    {
        $api = $this->createMock(TelegramBotApi::class);
        $api->expects($this->never())->method('getMe');
        $service = $this->service($api, $this->createMock(TelegramBotRepository::class), $this->createMock(CredentialVaultInterface::class), 'http://localhost:8000');

        try {
            $service->connect($this->user(), self::TOKEN);
            $this->fail('A local APP_URL must be refused');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::PUBLIC_URL_REQUIRED, $e->errorCode);
        }
    }

    public function testConnectStoresTheTokenAndReturnsAPairingLink(): void
    {
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('getMe')->willReturn(new TelegramBotIdentity(4242, 'synaplan_test_bot'));
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects($this->once())->method('store')->with(7, TelegramBot::CREDENTIAL_KIND, self::TOKEN)->willReturn(9);
        $saved = null;
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn(null);
        $bots->method('save')->willReturnCallback(function (TelegramBot $bot) use (&$saved): void {
            $saved = $bot;
        });

        $state = $this->service($api, $bots, $vault, 'https://chat.example.com')->connect($this->user(), self::TOKEN);

        $this->assertInstanceOf(TelegramBot::class, $saved);
        $this->assertSame('pending_pairing', $state['status']);
        $this->assertSame('synaplan_test_bot', $state['botUsername']);
        $this->assertIsString($state['pairingLink']);
        $this->assertStringStartsWith('https://t.me/synaplan_test_bot?start=', $state['pairingLink']);
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($state));
        $this->assertSame(9, $saved->getCredentialId());
    }

    public function testReconnectKeepsTheBotKeyAndTheChat(): void
    {
        $existing = new TelegramBot(7, 'same-key', 1, 'old_bot');
        $existing->setChatId(42);
        $existing->setCredentialId(3);
        $existing->setStatus(TelegramBot::STATUS_CONNECTED);
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('getMe')->willReturn(new TelegramBotIdentity(4242, 'synaplan_test_bot'));
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn(self::TOKEN);
        $vault->method('store')->willReturn(9);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($existing);

        $this->service($api, $bots, $vault, 'https://chat.example.com')->connect($this->user(), self::TOKEN);

        $this->assertSame('same-key', $existing->getBotKey());
        $this->assertSame(42, $existing->getChatId());
        $this->assertSame(TelegramBot::STATUS_PENDING, $existing->getStatus());
        $this->assertNull($existing->getTgUserId());
    }

    public function testWebhookFailureForgetsTheNewCredential(): void
    {
        $existing = new TelegramBot(7, 'same-key', 1, 'old_bot');
        $existing->setCredentialId(3);
        $existing->setStatus(TelegramBot::STATUS_CONNECTED);
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('getMe')->willReturn(new TelegramBotIdentity(4242, 'synaplan_test_bot'));
        $api->method('setWebhook')->willThrowException(new TelegramChannelException(TelegramChannelException::WEBHOOK_FAILED));
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn(self::TOKEN);
        $vault->method('store')->willReturn(9);
        $vault->expects($this->exactly(2))->method('forget');
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($existing);

        try {
            $this->service($api, $bots, $vault, 'https://chat.example.com')->connect($this->user(), self::TOKEN);
            $this->fail('A failed webhook must surface');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::WEBHOOK_FAILED, $e->errorCode);
        }

        $this->assertSame(TelegramBot::STATUS_ERROR, $existing->getStatus());
        $this->assertNull($existing->getCredentialId());
    }

    public function testPairAcceptsTheMatchingCodeOnly(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setPairCode('GOODCODE');
        $bot->setPairCodeHash(hash('sha256', 'GOODCODE'));
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bots = $this->createMock(TelegramBotRepository::class);
        $service = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->createMock(CredentialVaultInterface::class), 'https://chat.example.com');

        $this->assertFalse($service->pair($bot, 'BADCODE1', '555', '555'));
        $this->assertSame(TelegramBot::STATUS_PENDING, $bot->getStatus());
        $this->assertTrue($service->pair($bot, 'GOODCODE', '555', '555'));
        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertSame('555', $bot->getTgUserId());
        $this->assertNull($bot->getPairCode());
    }

    public function testDisconnectKeepsTheChatAndDropsTheSecret(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setChatId(42);
        $bot->setCredentialId(3);
        $bot->setSecretHash(hash('sha256', 'secret'));
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn(self::TOKEN);
        $vault->expects($this->once())->method('forget')->with(3, 7);
        $api = $this->createMock(TelegramBotApi::class);
        $api->expects($this->once())->method('deleteWebhook')->with(self::TOKEN);

        $state = $this->service($api, $bots, $vault, 'https://chat.example.com')->disconnect($this->user());

        $this->assertSame('disconnected', $state['status']);
        $this->assertSame(42, $bot->getChatId());
        $this->assertSame(42, $state['chatId']);
        $this->assertSame('', $bot->getSecretHash());
        $this->assertNull($bot->getCredentialId());
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($state));
    }

    public function testAccountDeletionRemovesTheRowAndTokenAndReturnsTheTokenForTheWebhook(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setCredentialId(3);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->expects($this->once())->method('findOneByOwner')->with(7)->willReturn($bot);
        $bots->expects($this->once())->method('remove')->with($bot, false);
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn(self::TOKEN);
        $vault->expects($this->once())->method('forget')->with(3, 7);
        $api = $this->createMock(TelegramBotApi::class);
        $api->expects($this->never())->method('deleteWebhook');

        $token = $this->service($api, $bots, $vault, 'https://chat.example.com')->removeForOwner(7);

        $this->assertSame(self::TOKEN, $token);
    }

    public function testAccountDeletionWithoutABotDoesNothing(): void
    {
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn(null);
        $bots->expects($this->never())->method('remove');

        $token = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->createMock(CredentialVaultInterface::class), 'https://chat.example.com')->removeForOwner(7);

        $this->assertNull($token);
    }

    public function testWebhookDropAfterDeletionSwallowsTelegramErrors(): void
    {
        $api = $this->createMock(TelegramBotApi::class);
        $api->expects($this->once())->method('deleteWebhook')
            ->willThrowException(new TelegramChannelException(TelegramChannelException::TOKEN_REVOKED));

        $this->service($api, $this->createMock(TelegramBotRepository::class), $this->createMock(CredentialVaultInterface::class), 'https://chat.example.com')
            ->dropWebhookForToken(self::TOKEN);

        $this->addToAssertionCount(1);
    }

    private function service(
        TelegramBotApi $api,
        TelegramBotRepository $bots,
        CredentialVaultInterface $vault,
        string $appUrl,
    ): TelegramConnectionService {
        return new TelegramConnectionService(
            $bots,
            $api,
            new PublicWebhookUrlValidator(false),
            $vault,
            new NullLogger(),
            $appUrl,
            '',
        );
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
