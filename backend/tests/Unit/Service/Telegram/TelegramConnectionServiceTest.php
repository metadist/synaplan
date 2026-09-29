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
use App\Service\Telegram\TelegramCopy;
use App\Service\Telegram\TelegramPairResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

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

    public function testReconnectingTheSameBotKeepsTheBotKeyAndTheChat(): void
    {
        $existing = new TelegramBot(7, 'same-key', 4242, 'synaplan_test_bot');
        $existing->setChatId(42);
        $existing->setLastMessageAt(1_700_000_000);
        $existing->setCredentialId(3);
        $existing->setStatus(TelegramBot::STATUS_ERROR);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($existing);

        $this->service($this->identityApi(), $bots, $this->vault(), 'https://chat.example.com')->connect($this->user(), self::TOKEN);

        $this->assertSame('same-key', $existing->getBotKey());
        $this->assertSame(42, $existing->getChatId());
        $this->assertSame(1_700_000_000, $existing->getLastMessageAt());
        $this->assertSame(TelegramBot::STATUS_PENDING, $existing->getStatus());
        $this->assertNull($existing->getTgUserId());
    }

    public function testConnectingADifferentBotStartsANewThread(): void
    {
        $existing = new TelegramBot(7, 'same-key', 1, 'old_bot');
        $existing->setChatId(42);
        $existing->setLastMessageAt(1_700_000_000);
        $existing->setCredentialId(3);
        $existing->setStatus(TelegramBot::STATUS_DISCONNECTED);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($existing);

        $state = $this->service($this->identityApi(), $bots, $this->vault(), 'https://chat.example.com')->connect($this->user(), self::TOKEN);

        $this->assertNull($existing->getChatId());
        $this->assertNull($state['lastMessageAt']);
        $this->assertSame(4242, $existing->getBotId());
        $this->assertSame('synaplan_test_bot', $existing->getBotUsername());
    }

    public function testABotThatAnotherAccountUsesIsRefusedWithoutTouchingIt(): void
    {
        $other = new TelegramBot(8, 'other-key', 4242, 'synaplan_test_bot');
        $other->setStatus(TelegramBot::STATUS_CONNECTED);
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('getMe')->willReturn(new TelegramBotIdentity(4242, 'synaplan_test_bot'));
        $api->expects($this->never())->method('setWebhook');
        $vault = $this->createMock(CredentialVaultInterface::class);
        $vault->expects($this->never())->method('store');
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->expects($this->once())->method('findActiveByBotIdForOtherOwner')->with(4242, 7)->willReturn($other);

        try {
            $this->service($api, $bots, $vault, 'https://chat.example.com')->connect($this->user(), self::TOKEN);
            $this->fail('A bot held by another account must be refused');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::BOT_IN_USE, $e->errorCode);
        }
        $this->assertSame(TelegramBot::STATUS_CONNECTED, $other->getStatus());
    }

    public function testThePairingLinkExpires(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setPairCode('GOODCODE');
        $bot->setPairCodeExpires(time() - 1);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $service = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->vault(), 'https://chat.example.com');

        $state = $service->status($this->user());

        $this->assertSame('pending_pairing', $state['status']);
        $this->assertNull($state['pairingLink']);
        $this->assertSame(TelegramPairResult::Expired, $service->pair($bot, 'GOODCODE', '555', '555'));
        $this->assertSame(TelegramBot::STATUS_PENDING, $bot->getStatus());
    }

    public function testRenewingIssuesAFreshCodeWithoutTheToken(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setPairCode('OLDCODE1');
        $bot->setPairCodeExpires(time() - 1);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $bots->expects($this->once())->method('save')->with($bot);
        $api = $this->createMock(TelegramBotApi::class);
        $api->expects($this->never())->method('getMe');

        $state = $this->service($api, $bots, $this->vault(), 'https://chat.example.com')->renewPairing($this->user());

        $this->assertNotSame('OLDCODE1', $bot->getPairCode());
        $this->assertIsString($state['pairingLink']);
        $this->assertGreaterThan(time(), $state['pairingExpiresAt']);
    }

    public function testRenewingAConnectedBotChangesNothing(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $bots->expects($this->never())->method('save');

        $state = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->vault(), 'https://chat.example.com')->renewPairing($this->user());

        $this->assertSame('connected', $state['status']);
        $this->assertNull($state['pairingLink']);
    }

    public function testLastMessageAtIsTheLastExchangeNotTheLastChange(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $service = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->vault(), 'https://chat.example.com');

        $this->assertNull($service->status($this->user())['lastMessageAt']);
        $service->noteExchange($bot);
        $this->assertIsInt($service->status($this->user())['lastMessageAt']);
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
        $bot->setPairCodeExpires(time() + 600);
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bots = $this->createMock(TelegramBotRepository::class);
        $service = $this->service($this->createMock(TelegramBotApi::class), $bots, $this->createMock(CredentialVaultInterface::class), 'https://chat.example.com');

        $this->assertSame(TelegramPairResult::Mismatch, $service->pair($bot, 'BADCODE1', '555', '555'));
        $this->assertSame(TelegramBot::STATUS_PENDING, $bot->getStatus());
        $this->assertSame(TelegramPairResult::Paired, $service->pair($bot, 'GOODCODE', '555', '555'));
        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertSame('555', $bot->getTgUserId());
        $this->assertNull($bot->getPairCode());
        $this->assertNull($bot->getPairCodeExpires());
    }

    public function testRecoverClearsABlockedState(): void
    {
        $bot = new TelegramBot(7, 'key', 1, 'synaplan_test_bot');
        $bot->setStatus(TelegramBot::STATUS_ERROR);
        $bot->setErrorCode(TelegramChannelException::BOT_BLOCKED);
        $service = $this->service($this->createMock(TelegramBotApi::class), $this->createMock(TelegramBotRepository::class), $this->vault(), 'https://chat.example.com');

        $service->recover($bot);

        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertNull($bot->getErrorCode());
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
            new TelegramCopy($this->translator()),
            $appUrl,
            '',
        );
    }

    private function identityApi(): TelegramBotApi
    {
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('getMe')->willReturn(new TelegramBotIdentity(4242, 'synaplan_test_bot'));

        return $api;
    }

    private function vault(): CredentialVaultInterface
    {
        $vault = $this->createStub(CredentialVaultInterface::class);
        $vault->method('reveal')->willReturn(self::TOKEN);
        $vault->method('store')->willReturn(9);

        return $vault;
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }

    private function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['de', 'en', 'es', 'fr', 'tr'] as $locale) {
            $translator->addResource('yaml', dirname(__DIR__, 4).'/translations/telegram.'.$locale.'.yaml', $locale, 'telegram');
        }

        return $translator;
    }
}
