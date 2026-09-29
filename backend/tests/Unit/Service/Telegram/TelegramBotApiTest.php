<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramChannelException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelegramBotApiTest extends TestCase
{
    public function testGetMeReadsTheBotIdentity(): void
    {
        $api = $this->api([
            new MockResponse((string) json_encode([
                'ok' => true,
                'result' => ['id' => 42, 'is_bot' => true, 'username' => 'synaplan_bot'],
            ])),
        ]);

        $identity = $api->getMe('123456789:AAHexampleToken');

        $this->assertSame(42, $identity->id);
        $this->assertSame('synaplan_bot', $identity->username);
    }

    public function testUnauthorizedGetMeIsAnInvalidToken(): void
    {
        $api = $this->api([
            new MockResponse((string) json_encode([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized: bot token is invalid',
            ]), ['http_code' => 401]),
        ]);

        try {
            $api->getMe('123456789:AAHexampleToken');
            $this->fail('Expected an invalid-token error');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::TOKEN_INVALID, $e->errorCode);
            $this->assertStringNotContainsString('Unauthorized', $e->getMessage());
        }
    }

    public function testSendMessageSplitsLongText(): void
    {
        $bodies = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = $options['body'] ?? '';

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => 1]]));
        });

        $api->sendMessage('123456789:AAHexampleToken', '99', str_repeat('a', 5000));

        $this->assertCount(2, $bodies);
    }

    public function testSendMessageUsesTelegramHtml(): void
    {
        $bodies = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => 1]]));
        });

        $api->sendMessage('123456789:AAHexampleToken', '99', 'This is **bold** & <raw>');

        $this->assertCount(1, $bodies);
        $this->assertSame('HTML', $bodies[0]['parse_mode']);
        $this->assertSame('This is <b>bold</b> &amp; &lt;raw&gt;', $bodies[0]['text']);
    }

    public function testUnparsableMarkupFallsBackToPlainText(): void
    {
        $bodies = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $bodies[] = $body;
            if (isset($body['parse_mode'])) {
                return new MockResponse((string) json_encode([
                    'ok' => false,
                    'error_code' => 400,
                    'description' => "Bad Request: can't parse entities",
                ]), ['http_code' => 400]);
            }

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => 2]]));
        });

        $api->sendMessage('123456789:AAHexampleToken', '99', 'This is **bold**');

        $this->assertCount(2, $bodies);
        $this->assertArrayNotHasKey('parse_mode', $bodies[1]);
        $this->assertSame('This is **bold**', $bodies[1]['text']);
    }

    public function testBlockedSendIsABotBlockedCode(): void
    {
        $api = $this->api([
            new MockResponse((string) json_encode([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ]), ['http_code' => 403]),
        ]);

        try {
            $api->sendMessage('123456789:AAHexampleToken', '99', 'hello');
            $this->fail('Expected a blocked-bot error');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::BOT_BLOCKED, $e->errorCode);
            $this->assertStringNotContainsString('Forbidden', $e->getMessage());
        }
    }

    /**
     * @param list<MockResponse>|callable $responses
     */
    private function api(array|callable $responses): TelegramBotApi
    {
        return new TelegramBotApi(new MockHttpClient($responses), new NullLogger(), 'https://api.telegram.org');
    }
}
