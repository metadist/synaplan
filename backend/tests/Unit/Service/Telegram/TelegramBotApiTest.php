<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramFileMethod;
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

    public function testTheWebhookAsksForEditsAndButtons(): void
    {
        $bodies = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse((string) json_encode(['ok' => true, 'result' => true]));
        });

        $api->setWebhook('123456789:AAHexampleToken', 'https://app.example/hook', 'secret');

        $this->assertSame(['message', 'edited_message', 'callback_query'], $bodies[0]['allowed_updates']);
    }

    public function testTheReplyReferenceIsOnTheFirstChunkAndTheButtonsOnTheLast(): void
    {
        $bodies = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => count($bodies)]]));
        });

        $ids = $api->sendMessage('123456789:AAHexampleToken', '99', str_repeat('a', 5000), ['inline_keyboard' => []], 7);

        $this->assertSame([1, 2], $ids);
        $this->assertSame(7, $bodies[0]['reply_parameters']['message_id']);
        $this->assertArrayNotHasKey('reply_markup', $bodies[0]);
        $this->assertArrayNotHasKey('reply_parameters', $bodies[1]);
        $this->assertArrayHasKey('reply_markup', $bodies[1]);
    }

    public function testARefusedEditLetsTheCallerSendANewMessage(): void
    {
        $api = $this->api([
            new MockResponse((string) json_encode(['ok' => false, 'error_code' => 400, 'description' => 'message can\'t be edited']), ['http_code' => 400]),
            new MockResponse((string) json_encode(['ok' => false, 'error_code' => 400, 'description' => 'message can\'t be edited']), ['http_code' => 400]),
        ]);

        $this->assertFalse($api->editMessageText('123456789:AAHexampleToken', '99', 5, 'new'));
    }

    public function testAFileWithoutADownloadPathIsTooLarge(): void
    {
        $api = $this->api([new MockResponse((string) json_encode(['ok' => true, 'result' => ['file_id' => 'x', 'file_size' => 30000000]]))]);

        $this->expectExceptionObject(new TelegramChannelException(TelegramChannelException::FILE_TOO_LARGE));
        $api->getFile('123456789:AAHexampleToken', 'x');
    }

    public function testADownloadStopsAtTheLimit(): void
    {
        $api = $this->api([new MockResponse(str_repeat('a', 2048))]);

        try {
            $api->downloadFile('123456789:AAHexampleToken', 'photos/a.jpg', 1024);
            $this->fail('Expected the size cap to stop the download');
        } catch (TelegramChannelException $e) {
            $this->assertSame(TelegramChannelException::FILE_TOO_LARGE, $e->errorCode);
        }
    }

    public function testADownloadReadsTheFileUrl(): void
    {
        $urls = [];
        $api = $this->api(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('content');
        });

        $this->assertSame('content', $api->downloadFile('123456789:AAHexampleToken', 'photos/a.jpg'));
        $this->assertSame('https://api.telegram.org/file/bot123456789%3AAAHexampleToken/photos/a.jpg', $urls[0]);
    }

    public function testAFileIsUploadedAsMultipart(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tg');
        file_put_contents((string) $path, 'png');
        $requests = [];
        $api = $this->api(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $body = '';
            $read = $options['body'];
            if ($read instanceof \Closure) {
                while ('' !== ($chunk = (string) $read(16384))) {
                    $body .= $chunk;
                }
            }
            $requests[] = ['url' => $url, 'body' => $body];

            return new MockResponse((string) json_encode(['ok' => true, 'result' => ['message_id' => 9]]));
        });

        $id = $api->sendFile('123456789:AAHexampleToken', '99', TelegramFileMethod::Photo, (string) $path, 'cat.png', 'A **cat**');
        unlink((string) $path);

        $this->assertSame(9, $id);
        $this->assertStringEndsWith('/sendPhoto', $requests[0]['url']);
        $this->assertStringContainsString('name="photo"; filename="cat.png"', $requests[0]['body']);
        $this->assertStringContainsString('A <b>cat</b>', $requests[0]['body']);
    }

    /**
     * @param list<MockResponse>|callable $responses
     */
    private function api(array|callable $responses): TelegramBotApi
    {
        return new TelegramBotApi(new MockHttpClient($responses), new NullLogger(), 'https://api.telegram.org');
    }
}
