<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramFileMethod;
use App\Service\Telegram\TelegramMediaSender;
use App\Service\Telegram\TelegramOutgoingFile;
use App\Service\Telegram\TelegramVoiceConverter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class TelegramMediaSenderTest extends TestCase
{
    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];
    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/tg-send-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/7', 0o777, true);
        foreach (['a.png', 'b.mp4', 'c.mp3', 'd.docx', 'e.gif'] as $name) {
            file_put_contents($this->uploadDir.'/7/'.$name, 'x');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/7/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->uploadDir.'/7');
        rmdir($this->uploadDir);
    }

    public function testEachTypeUsesItsTelegramMethod(): void
    {
        $sender = $this->sender(voice: $this->uploadDir.'/7/c.mp3');
        $sender->deliver('t', '1', '', [
            new TelegramOutgoingFile('7/a.png', 'image'),
            new TelegramOutgoingFile('/api/v1/files/uploads/7/b.mp4', 'video'),
            new TelegramOutgoingFile('7/c.mp3', 'audio'),
            new TelegramOutgoingFile('7/d.docx', 'document'),
            new TelegramOutgoingFile('7/e.gif', 'image'),
        ]);

        $methods = array_map(static fn (array $args): mixed => $args[2], $this->calls('sendFile'));
        $this->assertSame([TelegramFileMethod::Photo, TelegramFileMethod::Video, TelegramFileMethod::Voice, TelegramFileMethod::Document, TelegramFileMethod::Document], $methods);
    }

    public function testAudioFallsBackToAnAudioFileWhenItCannotBeConverted(): void
    {
        $this->sender(voice: null)->deliver('t', '1', '', [new TelegramOutgoingFile('7/c.mp3', 'audio')]);

        $this->assertSame(TelegramFileMethod::Audio, $this->calls('sendFile')[0][2]);
    }

    public function testALongAnswerIsSentBeforeTheFile(): void
    {
        $keyboard = ['inline_keyboard' => []];
        $delivery = $this->sender()->deliver('t', '1', str_repeat('a', 1100), [new TelegramOutgoingFile('7/a.png', 'image')], $keyboard, 5);

        $this->assertSame(5, $this->calls('sendMessage')[0][4]);
        $this->assertNull($this->calls('sendMessage')[0][3]);
        $this->assertSame('', $this->calls('sendFile')[0][5]);
        $this->assertSame($keyboard, $this->calls('sendFile')[0][6]);
        $this->assertNull($this->calls('sendFile')[0][7]);
        $this->assertSame([100, 200], $delivery->messageIds);
        $this->assertFalse($delivery->textOnly);
    }

    public function testAPathOutsideTheUploadsIsNeverSent(): void
    {
        $delivery = $this->sender()->deliver('t', '1', 'Here.', [new TelegramOutgoingFile('../../../etc/passwd', 'document')]);

        $this->assertSame([], $this->calls('sendFile'));
        $this->assertCount(1, $delivery->unsent);
        $this->assertSame('Here.', $this->calls('sendMessage')[0][2]);
        $this->assertTrue($delivery->textOnly);
    }

    public function testAFailedUploadStillDeliversTheCaption(): void
    {
        $delivery = $this->sender(uploadError: TelegramChannelException::SEND_FAILED)->deliver('t', '1', 'Your cat.', [new TelegramOutgoingFile('7/a.png', 'image')]);

        $this->assertSame('Your cat.', $this->calls('sendMessage')[0][2]);
        $this->assertCount(1, $delivery->unsent);
    }

    public function testABlockedBotStopsTheDelivery(): void
    {
        $this->expectExceptionObject(new TelegramChannelException(TelegramChannelException::BOT_BLOCKED));

        $this->sender(uploadError: TelegramChannelException::BOT_BLOCKED)->deliver('t', '1', 'Your cat.', [new TelegramOutgoingFile('7/a.png', 'image')]);
    }

    private function sender(?string $voice = null, ?string $uploadError = null): TelegramMediaSender
    {
        $api = $this->createStub(TelegramBotApi::class);
        $api->method('sendMessage')->willReturnCallback(function (mixed ...$args): array {
            $this->calls[] = ['method' => 'sendMessage', 'args' => array_values($args)];

            return [100];
        });
        $api->method('sendFile')->willReturnCallback(function (mixed ...$args) use ($uploadError): int {
            $this->calls[] = ['method' => 'sendFile', 'args' => array_values($args) + [5 => '', 6 => null, 7 => null]];
            if (null !== $uploadError) {
                throw new TelegramChannelException($uploadError);
            }

            return 200;
        });
        $converter = $this->createStub(TelegramVoiceConverter::class);
        $converter->method('toVoice')->willReturnCallback(static function () use ($voice): ?string {
            if (null === $voice) {
                return null;
            }
            $copy = $voice.'.ogg';
            copy($voice, $copy);

            return $copy;
        });

        return new TelegramMediaSender($api, $converter, new NullLogger(), $this->uploadDir);
    }

    /**
     * @return list<list<mixed>>
     */
    private function calls(string $method): array
    {
        $found = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                $found[] = $call['args'];
            }
        }

        return $found;
    }
}
