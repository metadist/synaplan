<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\EventListener\TelegramMediaJobDeliveryListener;
use App\Message\DeliverTelegramMediaCommand;
use App\Repository\MessageRepository;
use App\Repository\TelegramBotRepository;
use App\Repository\UserRepository;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobTerminalEvent;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramConnectionService;
use App\Service\Telegram\TelegramConversation;
use App\Service\Telegram\TelegramCopy;
use App\Service\Telegram\TelegramFileMethod;
use App\Service\Telegram\TelegramMediaJobDelivery;
use App\Service\Telegram\TelegramMediaSender;
use App\Service\Telegram\TelegramMessageStore;
use App\Service\Telegram\TelegramVoiceConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

#[AllowMockObjectsWithoutExpectations]
final class TelegramMediaJobDeliveryTest extends TestCase
{
    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];
    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/tg-job-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/7', 0o777, true);
        file_put_contents($this->uploadDir.'/7/fox.png', 'png');
    }

    protected function tearDown(): void
    {
        unlink($this->uploadDir.'/7/fox.png');
        rmdir($this->uploadDir.'/7');
        rmdir($this->uploadDir);
    }

    public function testAFinishedImageIsUploadedAsAReplyToTheAnnouncement(): void
    {
        $answer = $this->answer();
        $this->delivery($answer, $this->job(MediaJob::STATUS_COMPLETED))->deliver('job-1', 55);

        $upload = $this->calls('sendFile')[0];
        $this->assertSame(TelegramFileMethod::Photo, $upload[2]);
        $this->assertSame('a:55', $upload[6]['inline_keyboard'][0][0]['callback_data']);
        $this->assertNull($this->calls('editMessageReplyMarkup')[0][3]);
        $this->assertSame(MediaJob::STATUS_COMPLETED, $answer->getMeta('tg_job_'.substr(hash('sha256', 'job-1'), 0, 32)));
    }

    public function testAJobIsDeliveredOnlyOnce(): void
    {
        $answer = $this->answer();
        $delivery = $this->delivery($answer, $this->job(MediaJob::STATUS_COMPLETED));

        $delivery->deliver('job-1', 55);
        $delivery->deliver('job-1', 55);

        $this->assertCount(1, $this->calls('sendFile'));
    }

    public function testAFailedRenderSaysSoInOneSentence(): void
    {
        $this->delivery($this->answer(), $this->job(MediaJob::STATUS_TIMED_OUT))->deliver('job-1', 55);

        $message = $this->calls('sendMessage')[0];
        $this->assertStringStartsWith('The image could not be created.', $message[2]);
        $this->assertSame(1000, $message[4]);
    }

    public function testACancelledRenderSaysNothingWasCreated(): void
    {
        $this->delivery($this->answer(), $this->job(MediaJob::STATUS_CANCELLED))->deliver('job-1', 55);

        $this->assertSame('Cancelled. Nothing was created.', $this->calls('sendMessage')[0][2]);
    }

    public function testADisconnectedBotGetsNothing(): void
    {
        $bot = $this->bot();
        $bot->setStatus(TelegramBot::STATUS_DISCONNECTED);
        $this->delivery($this->answer(), $this->job(MediaJob::STATUS_COMPLETED), $bot)->deliver('job-1', 55);

        $this->assertSame([], $this->calls);
    }

    public function testARePairedChatGetsNothing(): void
    {
        $bot = $this->bot();
        $bot->setTgChatId('999');
        $this->delivery($this->answer(), $this->job(MediaJob::STATUS_COMPLETED), $bot)->deliver('job-1', 55);

        $this->assertSame([], $this->calls);
    }

    public function testAJobBoundToAnotherMessageIsIgnored(): void
    {
        $job = $this->job(MediaJob::STATUS_COMPLETED)->setMessageId(56);
        $this->delivery($this->answer(), $job)->deliver('job-1', 55);

        $this->assertSame([], $this->calls);
    }

    public function testTheListenerOnlyQueuesTelegramAnswers(): void
    {
        $telegram = $this->answer();
        $web = new Message();
        $web->setDirection('OUT');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($web, 56);
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('find')->willReturnCallback(static fn (mixed $id): ?Message => match ($id) {
            55 => $telegram,
            56 => $web,
            default => null,
        });
        $queued = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): Envelope {
            $queued[] = $message;

            return new Envelope($message);
        });
        $listener = new TelegramMediaJobDeliveryListener($messages, $bus);

        $listener(new MediaJobTerminalEvent($this->job(MediaJob::STATUS_COMPLETED), 55));
        $listener(new MediaJobTerminalEvent($this->job(MediaJob::STATUS_COMPLETED), 56));

        $this->assertCount(1, $queued);
        $this->assertInstanceOf(DeliverTelegramMediaCommand::class, $queued[0]);
        $this->assertSame('job-1', $queued[0]->getJobKey());
    }

    private function delivery(Message $answer, MediaJob $job, ?TelegramBot $bot = null): TelegramMediaJobDelivery
    {
        $bot ??= $this->bot();
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('find')->willReturn($answer);
        $bots = $this->createStub(TelegramBotRepository::class);
        $bots->method('findOneByOwner')->willReturn($bot);
        $user = $this->createStub(User::class);
        $user->method('getLocale')->willReturn('en');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $connections = $this->createStub(TelegramConnectionService::class);
        $connections->method('revealToken')->willReturn('123:token');
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('findByKey')->willReturn($job);
        $em = $this->createStub(EntityManagerInterface::class);

        $api = $this->createStub(TelegramBotApi::class);
        foreach (['sendFile', 'sendMessage', 'editMessageReplyMarkup'] as $method) {
            $api->method($method)->willReturnCallback(function (mixed ...$args) use ($method): mixed {
                $this->calls[] = ['method' => $method, 'args' => array_values($args)];

                return match ($method) {
                    'sendFile' => 2000,
                    'sendMessage' => [2001],
                    default => null,
                };
            });
        }

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 4).'/translations/telegram.en.yaml', 'en', 'telegram');
        $copy = new TelegramCopy($translator);
        $store = new TelegramMessageStore($em, $connections);

        return new TelegramMediaJobDelivery(
            $em,
            $messages,
            $bots,
            $users,
            $connections,
            $store,
            new TelegramMediaSender($api, $this->createStub(TelegramVoiceConverter::class), new NullLogger(), $this->uploadDir),
            $api,
            $this->createStub(TelegramConversation::class),
            $copy,
            $jobs,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
    }

    private function answer(): Message
    {
        $answer = new Message();
        $answer->setUserId(7);
        $answer->setDirection('OUT');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($answer, 55);
        $answer->setMeta('channel', 'telegram');
        $answer->setMeta('tg_chat_id', '555');
        $answer->setMeta(TelegramMessageStore::META_DELIVERED, '[1000]');

        return $answer;
    }

    private function job(string $status): MediaJob
    {
        return (new MediaJob('job-1'))
            ->setUserId(7)
            ->setType(MediaJob::TYPE_IMAGE)
            ->setMessageId(55)
            ->setStatus($status)
            ->setResult(MediaJob::STATUS_COMPLETED === $status ? ['file' => ['url' => '/api/v1/files/uploads/7/fox.png', 'type' => 'image']] : null);
    }

    private function bot(): TelegramBot
    {
        $bot = new TelegramBot(7, 'bot-key', 4242, 'synaplan_test_bot');
        (new \ReflectionProperty(TelegramBot::class, 'id'))->setValue($bot, 5);
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bot->setTgUserId('555');
        $bot->setTgChatId('555');

        return $bot;
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
