<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Message\ProcessTelegramAlbumCommand;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Repository\MessageRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\FeedbackExampleService;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobCanceller;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\Telegram\TelegramAlbumBuffer;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramCallbackService;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramConnectionService;
use App\Service\Telegram\TelegramConversation;
use App\Service\Telegram\TelegramCopy;
use App\Service\Telegram\TelegramFileMethod;
use App\Service\Telegram\TelegramInboundService;
use App\Service\Telegram\TelegramMediaDownloader;
use App\Service\Telegram\TelegramMediaRef;
use App\Service\Telegram\TelegramMediaRejected;
use App\Service\Telegram\TelegramMediaSender;
use App\Service\Telegram\TelegramMessageStore;
use App\Service\Telegram\TelegramPairResult;
use App\Service\Telegram\TelegramState;
use App\Service\Telegram\TelegramVoiceConverter;
use App\Service\Usage\RecordedUsage;
use App\Service\UserMemoryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

#[AllowMockObjectsWithoutExpectations]
final class TelegramInboundServiceTest extends TestCase
{
    /** @var list<array{text: string, markup: array<string, mixed>|null, replyTo: int|null}> */
    private array $sent = [];
    /** @var list<array{method: string, args: list<mixed>}> */
    private array $calls = [];
    /** @var list<Message> */
    private array $messages = [];
    /** @var list<array<string, mixed>> */
    private array $processed = [];
    /** @var list<object> */
    private array $dispatched = [];
    private string $uploadDir = '';
    private ?LockFactory $locks = null;
    private bool $chatBusy = false;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/tg-inbound-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/7', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/7/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->uploadDir.'/7');
        rmdir($this->uploadDir);
    }

    public function testAPhotoIsAttachedAndDescribed(): void
    {
        $attached = [];
        $service = $this->service(reply: 'A cat on a sofa.', attached: $attached);

        $service->handle(5, 1, $this->update(['photo' => [['file_id' => 'small', 'file_size' => 10], ['file_id' => 'big', 'file_size' => 900]]]));

        $this->assertSame(['big'], $attached);
        $this->assertSame('Describe what you see in this image.', $this->messages[0]->getText());
        $this->assertSame(1, $this->messages[0]->getFiles()->count());
        $this->assertSame('A cat on a sofa.', $this->texts()[0]);
    }

    public function testARejectedFileEndsTheTurnWithItsReason(): void
    {
        $service = $this->service(reply: 'never', reject: 'file_too_large');

        $service->handle(5, 1, $this->update(['document' => ['file_id' => 'doc', 'file_name' => 'big.pdf']]));

        $this->assertSame([], $this->processed);
        $this->assertSame(['This file is larger than 20 MB, so Telegram does not hand it to bots. Upload it in Synaplan instead.'], $this->texts());
        $this->assertSame('failed', $this->messages[0]->getStatus());
    }

    public function testAVoiceMessageWithoutWordsSaysSo(): void
    {
        $service = $this->service(reply: 'never');

        $service->handle(5, 1, $this->update(['voice' => ['file_id' => 'v', 'mime_type' => 'audio/ogg']]));

        $this->assertSame([], $this->processed);
        $this->assertSame(['I could not understand the voice message. Speak a little longer or send text.'], $this->texts());
    }

    public function testALocationBecomesTextForTheAi(): void
    {
        $service = $this->service(reply: 'That is Berlin.');

        $service->handle(5, 1, $this->update(['location' => ['latitude' => 52.52, 'longitude' => 13.405]]));

        $this->assertStringContainsString('52.52', $this->messages[0]->getText());
        $this->assertNotNull($this->messages[0]->getMeta(TelegramMessageStore::META_PAYLOAD));
        $this->assertSame(['That is Berlin.'], $this->texts());
    }

    public function testAnUnreadableMessageGetsOneSentenceAndIsNotStored(): void
    {
        $service = $this->service(persist: false);

        $service->handle(5, 1, $this->update(['game' => ['title' => 'x']]));

        $this->assertSame(['I cannot read this kind of message. Send text, a photo, a file or a voice message.'], $this->texts());
    }

    public function testStrangerIsRefusedAndNothingIsStored(): void
    {
        $bot = $this->connectedBot();
        $bot->setTgUserId('111');
        $service = $this->service(bot: $bot, persist: false);

        $service->handle(5, 1, $this->update(['text' => 'hello'], fromId: 999));

        $this->assertSame(['This bot only answers its owner.'], $this->texts());
    }

    public function testOwnerWithoutAStoredLanguageReadsTelegramLanguage(): void
    {
        $service = $this->service(persist: false, locale: 'en', languageStored: false);

        $service->handle(5, 1, $this->update(['text' => '/help'], languageCode: 'de'));

        $this->assertStringStartsWith('Senden Sie Text, Fotos', $this->texts()[0]);
    }

    public function testOwnerReadsTheirAppLanguage(): void
    {
        $service = $this->service(persist: false, locale: 'de');

        $service->handle(5, 1, $this->update(['text' => '/help']));

        $this->assertStringStartsWith('Senden Sie Text, Fotos', $this->texts()[0]);
    }

    public function testStrangerReadsTheirTelegramLanguage(): void
    {
        $service = $this->service(persist: false, locale: 'de');

        $service->handle(5, 1, $this->update(['text' => 'hello'], fromId: 999, languageCode: 'fr-FR'));

        $this->assertSame(['Ce bot ne répond qu\'à son propriétaire.'], $this->texts());
    }

    public function testPairingStoresTheTurnWithoutTheCode(): void
    {
        $bot = $this->connectedBot();
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setTgUserId(null);
        $service = $this->service(bot: $bot, pair: true);

        $service->handle(5, 1, $this->update(['text' => '/start GOODCODE', 'message_id' => 10]));

        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertStringStartsWith('Connected. Send text, photos', $this->texts()[0]);
        $this->assertCount(2, $this->messages);
        $this->assertSame('Connected from Telegram.', $this->messages[0]->getText());
        $this->assertSame('IN', $this->messages[0]->getDirection());
        $this->assertSame('TGRM', $this->messages[0]->getMessageType());
        $this->assertSame('OUT', $this->messages[1]->getDirection());
        $this->assertStringNotContainsString('GOODCODE', $this->messages[0]->getText());
    }

    public function testTextTurnStoresBothSidesAndOffersTheButtons(): void
    {
        $service = $this->service(reply: 'Four.');

        $service->handle(5, 31, $this->update(['text' => 'What is 2+2?', 'message_id' => 15]));

        $this->assertSame(['Four.'], $this->texts());
        [$in, $out] = $this->messages;
        $this->assertSame('complete', $in->getStatus());
        $this->assertSame('What is 2+2?', $in->getText());
        $this->assertSame('Four.', $out->getText());
        $this->assertSame('telegram', $in->getMeta('channel'));
        $this->assertSame('15', $in->getMeta('external_id'));
        $this->assertSame('4242:31', $in->getMeta(TelegramInboundService::META_UPDATE));
        $this->assertNull($out->getMeta(TelegramInboundService::META_UPDATE));
        $this->assertSame((string) $in->getId(), $out->getMeta(TelegramMessageStore::META_REPLY_TO));
        $this->assertSame('[1000]', $out->getMeta(TelegramMessageStore::META_DELIVERED));
        $this->assertSame('1', $out->getMeta(TelegramMessageStore::META_TEXT_ONLY));
        $buttons = array_column($this->sent[0]['markup']['inline_keyboard'][0] ?? [], 'callback_data');
        $this->assertSame(['a:'.$out->getId(), 'm:'.$out->getId(), 'f:'.$out->getId()], $buttons);
    }

    public function testTheMenuCommandKeepsItsArgument(): void
    {
        $service = $this->service(reply: 'Here.');

        $service->handle(5, 1, $this->update(['text' => '/pic@synaplan_test_bot a red fox']));

        $this->assertSame('/pic a red fox', $this->messages[0]->getText());
    }

    public function testARedeliveredUpdateThatAlreadyStoredItsTurnIsSkipped(): void
    {
        $service = $this->service(reply: 'never', persist: false, seen: true);

        $service->handle(5, 31, $this->update(['text' => 'What is 2+2?']));

        $this->assertSame([], $this->processed);
        $this->assertSame([], $this->sent);
    }

    public function testARedeliveredUpdateResumesATurnAKilledWorkerLeftProcessing(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 31, $this->update(['text' => 'What is 2+2?', 'message_id' => 15]));
        [$in] = $this->messages;
        $in->setStatus('processing');
        $this->messages = [$in];
        $this->sent = [];
        $this->processed = [];

        $service->handle(5, 31, $this->update(['text' => 'What is 2+2?', 'message_id' => 15]));

        $this->assertCount(1, $this->processed);
        $this->assertSame(['Four.'], $this->texts());
        $this->assertSame([15], array_column($this->sent, 'replyTo'));
        $this->assertSame('complete', $in->getStatus());
        $this->assertCount(1, array_filter($this->messages, static fn (Message $message): bool => 'IN' === $message->getDirection()));
    }

    public function testAnUpdateHeldByAnotherWorkerIsRetriedInsteadOfDropped(): void
    {
        $service = $this->service(reply: 'never', persist: false);
        $this->assertNotNull($this->locks);
        $held = $this->locks->createLock('telegram_turn_5_31');
        $this->assertTrue($held->acquire());

        try {
            $service->handle(5, 31, $this->update(['text' => 'What is 2+2?']));
            $this->fail('A locked update must be retried.');
        } catch (RecoverableMessageHandlingException $e) {
            $this->assertSame(30_000, $e->getRetryDelay());
        }
        $this->assertSame([], $this->processed);
    }

    public function testInboundMessageIsAnnouncedToTheOpenBrowser(): void
    {
        $activity = $this->createMock(ChatActivityNotifier::class);
        $activity->expects($this->once())->method('publishActivity')
            ->with($this->isInstanceOf(Chat::class), 7, 'IN', 'hello');
        $service = $this->service(reply: 'Hi.', activity: $activity);

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame(['Hi.'], $this->texts());
    }

    public function testReconnectedBotRenamesTheKeptChat(): void
    {
        $old = new Chat();
        $old->setUserId(7);
        $old->setSource('telegram');
        $old->setTitle('Telegram: @old_bot');
        (new \ReflectionProperty(Chat::class, 'id'))->setValue($old, 90);
        $bot = $this->connectedBot();
        $bot->setChatId(90);
        $service = $this->service(bot: $bot, reply: 'Hi.', chats: [90 => $old]);

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame('Telegram: @synaplan_test_bot', $old->getTitle());
        $this->assertSame(90, $bot->getChatId());
    }

    public function testATransientSendFailureKeepsTheBotConnected(): void
    {
        $bot = $this->connectedBot();
        $service = $this->service(bot: $bot, persist: false, sendError: TelegramChannelException::SEND_FAILED, expectMarkError: false);

        $service->handle(5, 1, $this->update(['game' => ['title' => 'x']]));

        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
    }

    public function testARevokedTokenMarksTheBotErrored(): void
    {
        $service = $this->service(persist: false, sendError: TelegramChannelException::TOKEN_REVOKED, expectMarkError: true);

        $service->handle(5, 1, $this->update(['game' => ['title' => 'x']]));
    }

    public function testAiFailureSendsThePresenterSentence(): void
    {
        $service = $this->service(failure: 'The model is not available. Pick another model in Settings.');

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame(['The model is not available. Pick another model in Settings.'], $this->texts());
    }

    public function testTheReplyCarriesTheModelsThatProducedIt(): void
    {
        $service = $this->service(reply: 'Four.');

        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));

        [$in, $out] = $this->messages;
        $this->assertSame('test', $out->getMeta('ai_chat_provider'));
        $this->assertSame('test-model', $out->getMeta('ai_chat_model'));
        $this->assertSame('1', $out->getMeta('ai_chat_model_id'));
        $this->assertSame('0.001000', $out->getMeta('ai_chat_cost'));
        $this->assertSame('groq', $out->getMeta('ai_sorting_provider'));
        $this->assertSame('sorter', $out->getMeta('ai_sorting_model'));
        $this->assertSame('general', $in->getTopic());
        $this->assertSame('general', $out->getTopic());
    }

    public function testAGeneratedImageIsUploadedWithTheAnswerAsCaption(): void
    {
        file_put_contents($this->uploadDir.'/7/cat.png', 'png');
        $service = $this->service(reply: 'Here is your cat.', extraMetadata: ['file' => ['path' => '7/cat.png', 'type' => 'image']]);

        $service->handle(5, 1, $this->update(['text' => 'Draw a cat']));

        $upload = $this->call('sendFile');
        $this->assertSame(TelegramFileMethod::Photo, $upload[2]);
        $this->assertSame('Here is your cat.', $upload[5]);
        $this->assertSame([], $this->sent);
        $this->assertSame('7/cat.png', $this->messages[1]->getFilePath());
        $this->assertSame('0', $this->messages[1]->getMeta(TelegramMessageStore::META_TEXT_ONLY));
    }

    public function testAnOfficeFileMarkerIsNotSentToTelegram(): void
    {
        file_put_contents($this->uploadDir.'/7/report.docx', 'docx');
        $service = $this->service(
            reply: "Your report is ready.\n__FILE_GENERATED__:report.docx",
            extraMetadata: ['generated_file' => ['path' => '7/report.docx', 'filename' => 'Report.docx']],
        );

        $service->handle(5, 1, $this->update(['text' => 'Write a report']));

        $upload = $this->call('sendFile');
        $this->assertSame(TelegramFileMethod::Document, $upload[2]);
        $this->assertSame('Report.docx', $upload[4]);
        $this->assertStringContainsString('Your report is ready.', $upload[5]);
        $this->assertStringNotContainsString('__FILE_GENERATED__', $upload[5]);
        $this->assertStringContainsString('report.docx', $upload[5]);
        $this->assertSame("Your report is ready.\n__FILE_GENERATED__:report.docx", $this->messages[1]->getText());
    }

    public function testAGeneratedDocumentMovesToTheAnswerAndIsNotALegacyFile(): void
    {
        $generated = new File();
        $generated->setFileName('report.docx');
        $id = new \ReflectionProperty(File::class, 'id');
        $id->setValue($generated, 55);

        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message, array $options = []) use ($generated): array {
            $this->assertIsCallable($options['heartbeat'] ?? null);
            ($options['heartbeat'])();
            $message->addFile($generated);

            return [
                'success' => true,
                'classification' => ['topic' => 'officemaker', 'language' => 'en'],
                'response' => [
                    'content' => '__FILE_GENERATED__:report.docx',
                    'metadata' => [
                        'provider' => 'test',
                        'model' => 'test-model',
                        'model_id' => 1,
                        'generated_file' => ['path' => '7/report.docx', 'filename' => 'Report.docx'],
                    ],
                ],
                'search_results' => [],
            ];
        });

        file_put_contents($this->uploadDir.'/7/report.docx', 'docx');
        $service = $this->service(processor: $processor);

        $service->handle(5, 1, $this->update(['text' => 'Write a report']));

        $inbound = $this->messages[0];
        $outbound = $this->messages[1];
        $this->assertSame('__FILE_GENERATED__:report.docx', $outbound->getText());
        $this->assertFalse($inbound->getFiles()->contains($generated));
        $this->assertTrue($outbound->getFiles()->contains($generated));
        $this->assertSame(0, $outbound->getFile());
        $this->assertSame('', $outbound->getFilePath());
        $upload = $this->call('sendFile');
        $this->assertSame(TelegramFileMethod::Document, $upload[2]);
        $this->assertSame('Report.docx', $upload[4]);
        $this->assertStringNotContainsString('__', $upload[5]);
        $this->assertStringContainsString('report.docx', $upload[5]);
        $this->assertSame([], $this->texts());
    }

    public function testAFileThatIsGoneIsLinkedInstead(): void
    {
        $service = $this->service(reply: 'Here.', extraMetadata: ['file' => ['path' => '7/missing.mp4', 'type' => 'video']]);

        $service->handle(5, 1, $this->update(['text' => 'Make a video']));

        $this->assertSame('Here.', $this->texts()[0]);
        $this->assertStringContainsString('https://app.example/?chat=', $this->texts()[1]);
    }

    public function testWebSourcesAreListedUnderTheAnswer(): void
    {
        $service = $this->service(reply: 'It rains.', search: ['query' => 'weather', 'results' => [
            ['url' => 'https://example.org/a', 'title' => 'Weather [today]'],
            ['url' => 'javascript:alert(1)', 'title' => 'bad'],
        ]]);

        $service->handle(5, 1, $this->update(['text' => 'Weather?']));

        $this->assertSame("It rains.\n\nSources:\n1. [Weather (today)](https://example.org/a)", $this->texts()[0]);
        $this->assertSame('2', $this->messages[1]->getMeta('web_search_results_count'));
    }

    public function testARunningRenderIsAnnouncedWithACancelButtonAndBoundToTheAnswer(): void
    {
        $jobs = $this->createMock(MediaJobService::class);
        $jobs->expects($this->once())->method('rebindMessage')->with('job-1', $this->greaterThan(0))->willReturn(null);
        $service = $this->service(
            reply: '',
            extraMetadata: ['media_job' => ['job_id' => 'job-1', 'type' => 'video', 'state' => 'running']],
            mediaJobs: $jobs,
        );

        $service->handle(5, 1, $this->update(['text' => '/vid a wave']));

        $this->assertSame(['Creating the video. This takes a few minutes; I will send it here when it is ready.'], $this->texts());
        $out = $this->messages[1];
        $this->assertSame([['text' => 'Cancel', 'callback_data' => 'c:'.$out->getId()]], $this->sent[0]['markup']['inline_keyboard'][0] ?? null);
        $this->assertStringContainsString('job-1', (string) $out->getMeta('media_job'));
    }

    public function testEditingTheLastQuestionReplacesTheAnswerInPlace(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?', 'message_id' => 20]));
        [$in, $out] = $this->messages;

        $service->handle(5, 2, $this->edit(['text' => 'What is 2+3?', 'message_id' => 20]));

        $this->assertSame('What is 2+3?', $in->getText());
        $this->assertStringContainsString('What is 2+2?', (string) $in->getMeta(TelegramMessageStore::META_EDITED_FROM));
        $edit = $this->call('editMessageText');
        $this->assertSame(1000, $edit[2]);
        $this->assertCount(3, $this->messages);
        $this->assertSame((string) $this->messages[2]->getId(), $out->getMeta(TelegramMessageStore::META_SUPERSEDED));
        $this->assertCount(1, $this->sent);
    }

    public function testEditingAnOlderQuestionAnswersAsAReply(): void
    {
        $service = $this->service(reply: 'Answer.');
        $service->handle(5, 1, $this->update(['text' => 'first', 'message_id' => 20]));
        $service->handle(5, 2, $this->update(['text' => 'second', 'message_id' => 21]));
        $firstAnswer = $this->messages[1];

        $service->handle(5, 3, $this->edit(['text' => 'first, edited', 'message_id' => 20]));

        $this->assertNull($this->call('editMessageText', required: false));
        $this->assertSame(20, $this->sent[2]['replyTo']);
        $this->assertNull($firstAnswer->getMeta(TelegramMessageStore::META_SUPERSEDED));
    }

    public function testAStrangersEditIsIgnored(): void
    {
        $service = $this->service(reply: 'Answer.');
        $service->handle(5, 1, $this->update(['text' => 'hello', 'message_id' => 20]));

        $service->handle(5, 2, $this->edit(['text' => 'changed', 'message_id' => 20], fromId: 999));

        $this->assertSame('hello', $this->messages[0]->getText());
        $this->assertCount(1, $this->sent);
    }

    public function testAgainAsksTheSameModelOnceMore(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));
        $out = $this->messages[1];

        $service->handle(5, 2, $this->press('a:'.$out->getId()));

        $again = $this->processed[1];
        $this->assertSame(1, $again['model_id']);
        $this->assertTrue($again['is_again']);
        $this->assertIsCallable($again['heartbeat']);
        $this->assertSame('cb-1', $this->call('answerCallbackQuery')[1]);
        $this->assertSame((string) $this->messages[2]->getId(), $out->getMeta(TelegramMessageStore::META_SUPERSEDED));
    }

    public function testOtherModelShowsTheSelectableModels(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));
        $out = $this->messages[1];

        $service->handle(5, 2, $this->press('m:'.$out->getId()));

        $markup = $this->call('editMessageReplyMarkup')[3];
        $this->assertIsArray($markup);
        $this->assertSame('p:'.$out->getId().':1', $markup['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('b:'.$out->getId(), $markup['inline_keyboard'][1][0]['callback_data']);
    }

    public function testAnUnknownModelIsRefused(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));

        $service->handle(5, 2, $this->press('p:'.$this->messages[1]->getId().':99'));

        $this->assertCount(1, $this->processed);
        $this->assertSame('This model is no longer available.', $this->call('answerCallbackQuery')[2]);
    }

    public function testAButtonFromSomeoneElseDoesNothing(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));

        $service->handle(5, 2, $this->press('a:'.$this->messages[1]->getId(), fromId: 999));

        $this->assertCount(1, $this->processed);
        $this->assertSame('This button no longer works here.', $this->call('answerCallbackQuery')[2]);
    }

    public function testAButtonForAnotherUsersMessageDoesNothing(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));
        $this->messages[1]->setUserId(8);

        $service->handle(5, 2, $this->press('a:'.$this->messages[1]->getId()));

        $this->assertCount(1, $this->processed);
    }

    public function testNotCorrectSavesTheNextMessageAsACorrection(): void
    {
        $feedback = $this->createMock(FeedbackExampleService::class);
        $service = $this->service(reply: 'Paris is in Italy.', feedback: $feedback);
        $service->handle(5, 1, $this->update(['text' => 'Where is Paris?']));
        $out = $this->messages[1];
        $feedback->expects($this->once())->method('createFalsePositive')
            ->with($this->anything(), 'Paris is in France.', $out->getId());

        $service->handle(5, 2, $this->press('f:'.$out->getId()));
        $service->handle(5, 3, $this->update(['text' => 'Paris is in France.', 'message_id' => 30]));

        $this->assertCount(1, $this->processed);
        $this->assertSame('Thanks. I will remember that for future answers.', $this->texts()[2]);
    }

    public function testTheChatIsFreeForTheNextTurnOnceAnAnswerIsSent(): void
    {
        $service = $this->service(reply: 'Hello.');

        $service->handle(5, 1, $this->update(['text' => 'Hi', 'message_id' => 50]));
        $service->handle(5, 2, $this->edit(['text' => 'Hi there', 'message_id' => 50]));

        $this->assertCount(2, $this->processed);
        $this->assertNotNull($this->locks);
        $this->assertTrue($this->locks->createLock('telegram_chat_5_555')->acquire());
    }

    public function testAnAlbumIsCollectedIntoOneTurn(): void
    {
        $attached = [];
        $service = $this->service(reply: 'Two photos.', attached: $attached);

        $service->handle(5, 1, $this->update(['media_group_id' => 'g1', 'message_id' => 40, 'caption' => 'Compare them', 'photo' => [['file_id' => 'p1']]]));
        $service->handle(5, 2, $this->update(['media_group_id' => 'g1', 'message_id' => 41, 'photo' => [['file_id' => 'p2']]]));

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ProcessTelegramAlbumCommand::class, $this->dispatched[0]);
        $this->assertCount(0, $this->messages);

        $service->handleAlbum(5, 'g1');

        $this->assertSame(['p1', 'p2'], $attached);
        $this->assertSame('Compare them', $this->messages[0]->getText());
        $this->assertSame('4242:1', $this->messages[0]->getMeta(TelegramInboundService::META_UPDATE));
        $this->assertSame(['Two photos.'], $this->texts());

        $service->handle(5, 2, $this->update(['media_group_id' => 'g1', 'message_id' => 41, 'photo' => [['file_id' => 'p2']]]));
        $this->assertCount(1, $this->processed);
    }

    public function testAProcessorCrashEndsTheTurnAsFailed(): void
    {
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willThrowException(new \RuntimeException('boom'));
        $service = $this->service(processor: $processor);

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame(['Something went wrong. Try sending the message again.'], $this->texts());
        $this->assertSame('failed', $this->messages[0]->getStatus());
        $this->assertSame('OUT', $this->messages[1]->getDirection());
    }

    public function testTheOwnerWritingAfterUnblockingReconnects(): void
    {
        $bot = $this->connectedBot();
        $bot->setStatus(TelegramBot::STATUS_ERROR);
        $bot->setErrorCode(TelegramChannelException::BOT_BLOCKED);
        $service = $this->service(bot: $bot, reply: 'Hi.');

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertSame(['Hi.'], $this->texts());
    }

    public function testARevokedTokenIsNotRecoveredByAMessage(): void
    {
        $bot = $this->connectedBot();
        $bot->setStatus(TelegramBot::STATUS_ERROR);
        $bot->setErrorCode(TelegramChannelException::TOKEN_REVOKED);
        $service = $this->service(bot: $bot, persist: false);

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame(TelegramBot::STATUS_ERROR, $bot->getStatus());
    }

    public function testAnExpiredCodeSaysSo(): void
    {
        $bot = $this->connectedBot();
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setTgUserId(null);
        $service = $this->service(bot: $bot, persist: false);

        $service->handle(5, 1, $this->update(['text' => '/start OLDCODE1']));

        $this->assertSame(['This link has expired. Create a new link on the Channels page in Synaplan.'], $this->texts());
    }

    public function testAPhotoWithoutCaptionIsAskedAboutInTheOwnersLanguage(): void
    {
        $service = $this->service(reply: 'Eine Katze.', locale: 'de');

        $service->handle(5, 1, $this->update(['photo' => [['file_id' => 'p', 'file_size' => 10]]]));

        $this->assertSame('Beschreibe, was du auf diesem Bild siehst.', $this->messages[0]->getText());
    }

    public function testASharedLocationIsDescribedInTheOwnersLanguage(): void
    {
        $service = $this->service(reply: 'Schön dort.', locale: 'de');

        $service->handle(5, 1, $this->update(['location' => ['latitude' => 48.1374, 'longitude' => 11.5755]]));

        $this->assertStringStartsWith('Geteilter Standort: 48.1374, 11.5755', $this->messages[0]->getText());
    }

    public function testCancelStopsEveryRunningRenderOfTheAnswer(): void
    {
        $running = $this->createStub(MediaJob::class);
        $running->method('isTerminal')->willReturn(false);
        $running->method('getUserId')->willReturn(7);
        $done = $this->createStub(MediaJob::class);
        $done->method('isTerminal')->willReturn(true);
        $jobs = $this->createStub(MediaJobService::class);
        $canceller = $this->createMock(MediaJobCanceller::class);
        $service = $this->service(
            reply: '',
            extraMetadata: ['task_plan_render' => ['cards' => [['job_id' => 'card-1']]]],
            mediaJobs: $jobs,
            canceller: $canceller,
        );
        $service->handle(5, 1, $this->update(['text' => 'Make a poster']));
        $out = $this->messages[1];
        $running->method('getMessageId')->willReturn($out->getId());
        $done->method('getMessageId')->willReturn($out->getId());
        $jobs->method('findByMessage')->willReturn([$running, $done]);
        $canceller->expects($this->once())->method('cancel')->with($running);

        $service->handle(5, 2, $this->press('c:'.$out->getId()));

        $this->assertSame([['text' => 'Cancel', 'callback_data' => 'c:'.$out->getId()]], $this->sent[0]['markup']['inline_keyboard'][0] ?? null);
    }

    public function testALiveLocationUpdateDoesNotWaitForARunningAnswer(): void
    {
        $service = $this->service(reply: 'Noted.');
        $service->handle(5, 1, $this->update(['location' => ['latitude' => 1.0, 'longitude' => 2.0, 'live_period' => 900], 'message_id' => 60]));
        $this->assertNotNull($this->locks);
        $running = $this->locks->createLock('telegram_chat_5_555');
        $this->assertTrue($running->acquire());

        $service->handle(5, 2, $this->edit(['location' => ['latitude' => 3.0, 'longitude' => 4.0, 'live_period' => 900], 'message_id' => 60]));

        $this->assertStringContainsString('"latitude":3', (string) $this->messages[0]->getMeta(TelegramMessageStore::META_PAYLOAD));
        $this->assertCount(1, $this->processed);
    }

    public function testAButtonPressedWhileAnAnswerRunsStopsTheSpinnerAtOnce(): void
    {
        $service = $this->service(reply: 'Four.');
        $service->handle(5, 1, $this->update(['text' => 'What is 2+2?']));
        $out = $this->messages[1];
        $this->chatBusy = true;

        $service->handle(5, 2, $this->press('a:'.$out->getId()));

        $acks = array_values(array_filter($this->calls, static fn (array $call): bool => 'answerCallbackQuery' === $call['method']));
        $this->assertCount(1, $acks);
        $this->assertSame('cb-1', $acks[0]['args'][1]);
        $this->assertNull($acks[0]['args'][2]);
        $this->assertCount(2, $this->processed);
    }

    public function testRateLimitDoesNotCallTheModel(): void
    {
        $service = $this->service(reply: 'never', allowed: false);

        $service->handle(5, 1, $this->update(['text' => 'hello']));

        $this->assertSame([], $this->processed);
        $this->assertSame(['You have reached the message limit, so I did not answer. Try again once your limit resets.'], $this->texts());
    }

    public function testDocReferencesBecomeLinksWhileTheStoredReplyKeepsTheTag(): void
    {
        $docs = $this->createMock(PlatformDocReferenceResolver::class);
        $docs->method('resolveDocTags')->willReturnCallback(
            static fn (string $text): string => str_replace(
                '[Doc:using-synaplan]',
                '[Using Synaplan](https://docs.example/using-synaplan)',
                $text,
            ),
        );
        $catalog = [[
            'slug' => 'using-synaplan',
            'title' => 'Using Synaplan',
            'url' => 'https://docs.example/using-synaplan',
        ]];
        $service = $this->service(
            reply: 'See [Doc:using-synaplan].',
            extraMetadata: ['docs' => $catalog],
            docs: $docs,
        );

        $service->handle(5, 1, $this->update(['text' => 'How do I use Synaplan?']));

        $outgoing = array_values(array_filter(
            $this->messages,
            static fn (Message $message): bool => 'OUT' === $message->getDirection(),
        ));
        $this->assertSame('See [Doc:using-synaplan].', $outgoing[0]->getText());
        $this->assertSame(json_encode($catalog, JSON_UNESCAPED_SLASHES), $outgoing[0]->getMeta('docs'));
        $this->assertSame(
            ['See [Using Synaplan](https://docs.example/using-synaplan).'],
            $this->texts(),
        );
        $actions = array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => 'sendChatAction' === $call['method'],
        ));
        $this->assertNotEmpty($actions);
        $this->assertSame('typing', $actions[0]['args'][2]);
    }

    /**
     * @param array<int, Chat>     $chats
     * @param array<string, mixed> $extraMetadata
     * @param array<string, mixed> $search
     * @param list<string>         $attached
     */
    private function service(
        ?TelegramBot $bot = null,
        bool $persist = true,
        bool $pair = false,
        ?string $reply = null,
        ?string $failure = null,
        ?MessageProcessor $processor = null,
        bool $allowed = true,
        string $locale = 'en',
        bool $languageStored = true,
        bool $seen = false,
        ?ChatActivityNotifier $activity = null,
        array $chats = [],
        ?string $sendError = null,
        ?bool $expectMarkError = null,
        array $extraMetadata = [],
        array $search = [],
        array &$attached = [],
        ?string $reject = null,
        ?MediaJobService $mediaJobs = null,
        ?FeedbackExampleService $feedback = null,
        ?MediaJobCanceller $canceller = null,
        ?PlatformDocReferenceResolver $docs = null,
        ?ClockInterface $clock = null,
    ): TelegramInboundService {
        $bot ??= $this->connectedBot();
        /** @var list<object> $pending */
        $pending = [];
        $seq = 100;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));
        $em->method('find')->willReturnCallback(function (string $class, mixed $id) use ($bot, &$chats): ?object {
            if (TelegramBot::class === $class) {
                return $bot;
            }
            if (Chat::class === $class && is_int($id)) {
                return $chats[$id] ?? null;
            }

            return null;
        });
        if (!$persist) {
            $em->expects($this->never())->method('persist');
        } else {
            $em->method('persist')->willReturnCallback(function (object $entity) use (&$pending): void {
                $pending[] = $entity;
            });
            $em->method('flush')->willReturnCallback(function () use (&$pending, &$seq, &$chats): void {
                foreach ($pending as $entity) {
                    $id = new \ReflectionProperty($entity, 'id');
                    if (null !== $id->getValue($entity)) {
                        continue;
                    }
                    $id->setValue($entity, $seq);
                    if ($entity instanceof Chat) {
                        $chats[$seq] = $entity;
                    }
                    if ($entity instanceof Message) {
                        $this->messages[] = $entity;
                    }
                    ++$seq;
                }
                $pending = [];
            });
        }

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getLocale')->willReturn($locale);
        $user->method('getPreferredLanguage')->willReturn($languageStored ? $locale : null);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $repository = $this->messageRepository($seen);
        $connections = $this->connections($pair, $expectMarkError);
        $api = $this->api($sendError);
        $processor ??= $this->processor($reply, $failure, $extraMetadata, $search);

        $errors = $this->createMock(ChatErrorPresenter::class);
        $errors->method('presentFromResult')->willReturn(new ChatErrorView(ChatFailureReason::ModelUnavailable, $failure ?? 'unavailable', null, true, 'raw'));

        $limits = $this->createMock(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => $allowed]);
        $limits->method('recordUsage')->willReturn(new RecordedUsage('0.001000', '0.000800', 1, 1, 2));

        $memories = $this->createMock(UserMemoryService::class);
        $memories->method('resolveMemoryTags')->willReturnArgument(0);
        $references = $this->createMock(MessageReferenceResolver::class);
        $references->method('resolveMessageTags')->willReturnArgument(0);

        $downloader = $this->createMock(TelegramMediaDownloader::class);
        $downloader->method('attach')->willReturnCallback(function (string $token, User $owner, TelegramMediaRef $ref, Message $message) use (&$attached, $reject): File {
            if (null !== $reject) {
                throw new TelegramMediaRejected($reject);
            }
            $attached[] = $ref->fileId;
            $file = new File();
            $message->addFile($file);

            return $file;
        });

        $copy = new TelegramCopy($this->translator());
        $store = new TelegramMessageStore($em, $connections);
        $sender = new TelegramMediaSender($api, $this->createStub(TelegramVoiceConverter::class), new NullLogger(), $this->uploadDir);
        $conversation = new TelegramConversation(
            $store,
            $connections,
            $api,
            $sender,
            $downloader,
            $copy,
            $this->createStub(MessagePreProcessor::class),
            $processor,
            $errors,
            $limits,
            $memories,
            $references,
            $activity ?? $this->createStub(ChatActivityNotifier::class),
            $mediaJobs ??= $this->createStub(MediaJobService::class),
            $this->createStub(MediaJobMessageSync::class),
            new NullLogger(),
            $docs ?? $this->docResolver(),
            $clock ?? new NativeClock(),
            'https://app.example',
        );

        $model = $this->createStub(Model::class);
        $model->method('getId')->willReturn(1);
        $model->method('getName')->willReturn('Test Model');
        $model->method('getActive')->willReturn(1);
        $models = $this->createStub(ModelRepository::class);
        $models->method('findByTag')->willReturn([$model]);

        $cache = new ArrayAdapter();
        $locks = $this->locks = new LockFactory($this->chatBusyStore());
        $state = new TelegramState($cache);
        $callbacks = new TelegramCallbackService(
            $repository,
            $models,
            $conversation,
            $store,
            $state,
            $api,
            $connections,
            $copy,
            $feedback ?? $this->createStub(FeedbackExampleService::class),
            $mediaJobs,
            $canceller ?? $this->createStub(MediaJobCanceller::class),
            new NullLogger(),
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new TelegramInboundService(
            $em,
            $users,
            $repository,
            $connections,
            $store,
            $conversation,
            $callbacks,
            new TelegramAlbumBuffer($cache, $locks),
            $state,
            $copy,
            $bus,
            $locks,
        );
    }

    private function messageRepository(bool $seen): MessageRepository
    {
        $repository = $this->createMock(MessageRepository::class);
        $repository->method('findTelegramUpdate')->willReturnCallback(function (int $userId, string $key, string $value) use ($seen): ?Message {
            if ($seen) {
                $stored = new Message();
                $stored->setStatus('complete');

                return $stored;
            }
            foreach ($this->messages as $message) {
                if ('IN' === $message->getDirection() && $message->getMeta($key) === $value) {
                    return $message;
                }
            }

            return null;
        });
        $repository->method('find')->willReturnCallback(function (mixed $id): ?Message {
            foreach ($this->messages as $message) {
                if ($message->getId() === $id) {
                    return $message;
                }
            }

            return null;
        });
        $repository->method('findTelegramInbound')->willReturnCallback(function (int $userId, string $chatId, string $externalId): ?Message {
            foreach (array_reverse($this->messages) as $message) {
                if ('IN' === $message->getDirection() && $message->getUserId() === $userId && $externalId === $message->getMeta('external_id')) {
                    return $message;
                }
            }

            return null;
        });
        $repository->method('findTelegramAnswer')->willReturnCallback(function (int $userId, int $inboundId): ?Message {
            foreach (array_reverse($this->messages) as $message) {
                if ('OUT' === $message->getDirection() && (string) $inboundId === $message->getMeta(TelegramMessageStore::META_REPLY_TO)
                    && null === $message->getMeta(TelegramMessageStore::META_SUPERSEDED)) {
                    return $message;
                }
            }

            return null;
        });
        $repository->method('hasInboundAfter')->willReturnCallback(function (int $chatId, int $messageId): bool {
            foreach ($this->messages as $message) {
                if ('IN' === $message->getDirection() && $message->getId() > $messageId) {
                    return true;
                }
            }

            return false;
        });

        return $repository;
    }

    private function connections(bool $pair, ?bool $expectMarkError): TelegramConnectionService
    {
        $connections = $this->createMock(TelegramConnectionService::class);
        $connections->method('revealToken')->willReturn('123456789:AAHexampleToken');
        $connections->method('pair')->willReturnCallback(function (TelegramBot $row, string $code, string $tgUser, string $tgChat) use ($pair): TelegramPairResult {
            if ('OLDCODE1' === $code) {
                return TelegramPairResult::Expired;
            }
            if (!$pair || 'GOODCODE' !== $code) {
                return TelegramPairResult::Mismatch;
            }
            $row->setStatus(TelegramBot::STATUS_CONNECTED);
            $row->setTgUserId($tgUser);
            $row->setTgChatId($tgChat);

            return TelegramPairResult::Paired;
        });
        $connections->method('recover')->willReturnCallback(function (TelegramBot $row): void {
            $row->setStatus(TelegramBot::STATUS_CONNECTED);
            $row->setErrorCode(null);
        });
        $connections->method('attachChat')->willReturnCallback(function (TelegramBot $row, int $chatId): void {
            $row->setChatId($chatId);
        });
        if (true === $expectMarkError) {
            $connections->expects($this->once())->method('markError');
        } elseif (false === $expectMarkError) {
            $connections->expects($this->never())->method('markError');
        }

        return $connections;
    }

    private function api(?string $sendError): TelegramBotApi
    {
        $nextId = 1000;
        $api = $this->createMock(TelegramBotApi::class);
        $api->method('sendMessage')->willReturnCallback(function (string $token, string $chatId, string $text, ?array $markup = null, ?int $replyTo = null) use ($sendError, &$nextId): array {
            if (null !== $sendError) {
                throw new TelegramChannelException($sendError);
            }
            $this->sent[] = ['text' => $text, 'markup' => $markup, 'replyTo' => $replyTo];

            return [$nextId++];
        });
        $api->method('sendChatAction')->willReturnCallback(function (string $token, string $chatId, string $action): void {
            $this->calls[] = ['method' => 'sendChatAction', 'args' => [$token, $chatId, $action]];
        });
        foreach (['sendFile', 'editMessageText', 'editMessageReplyMarkup', 'answerCallbackQuery'] as $method) {
            $api->method($method)->willReturnCallback(function (mixed ...$args) use ($method, &$nextId): mixed {
                $this->calls[] = ['method' => $method, 'args' => array_values($args)];

                return match ($method) {
                    'sendFile' => $nextId++,
                    'editMessageText' => true,
                    default => null,
                };
            });
        }

        return $api;
    }

    private function docResolver(): PlatformDocReferenceResolver
    {
        $docs = $this->createMock(PlatformDocReferenceResolver::class);
        $docs->method('resolveDocTags')->willReturnArgument(0);

        return $docs;
    }

    /**
     * @param array<string, mixed> $extraMetadata
     * @param array<string, mixed> $search
     */
    private function processor(?string $reply, ?string $failure, array $extraMetadata, array $search): MessageProcessor
    {
        $processor = $this->createMock(MessageProcessor::class);
        $processor->method('process')->willReturnCallback(function (Message $message, array $options = [], ?callable $status = null) use ($reply, $failure, $extraMetadata, $search): array {
            $this->processed[] = $options;
            $heartbeat = $options['heartbeat'] ?? null;
            if (is_callable($heartbeat)) {
                $heartbeat();
            }
            if (null !== $status) {
                $status(['status' => 'generating', 'message' => 'Generating response...']);
            }
            if (null !== $failure || null === $reply) {
                return ['success' => false];
            }

            return [
                'success' => true,
                'classification' => ['topic' => 'general', 'language' => 'en', 'sorting_provider' => 'groq', 'sorting_model_name' => 'sorter', 'sorting_model_id' => 3],
                'response' => [
                    'content' => $reply,
                    'metadata' => ['provider' => 'test', 'model' => 'test-model', 'usage' => ['prompt_tokens' => 1], 'model_id' => 1] + $extraMetadata,
                ],
                'search_results' => $search,
            ];
        });

        return $processor;
    }

    /**
     * @return list<string>
     */
    private function texts(): array
    {
        return array_column($this->sent, 'text');
    }

    /**
     * @return list<mixed>|null
     */
    private function call(string $method, bool $required = true): ?array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return $call['args'];
            }
        }
        if ($required) {
            $this->fail($method.' was not called');
        }

        return null;
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

    /**
     * A store in which the chat lock is held by another turn once after
     * {@see self::$chatBusy} is set, so the next attempt gets it.
     */
    private function chatBusyStore(): PersistingStoreInterface
    {
        $takeBusy = function (): bool {
            $busy = $this->chatBusy;
            $this->chatBusy = false;

            return $busy;
        };

        return new class(new InMemoryStore(), $takeBusy) implements PersistingStoreInterface {
            /**
             * @param \Closure(): bool $takeBusy
             */
            public function __construct(
                private readonly InMemoryStore $inner,
                private readonly \Closure $takeBusy,
            ) {
            }

            public function save(Key $key): void
            {
                if (str_starts_with((string) $key, 'telegram_chat_') && ($this->takeBusy)()) {
                    throw new LockConflictedException();
                }
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
    }

    private function connectedBot(): TelegramBot
    {
        $bot = new TelegramBot(7, 'bot-key', 4242, 'synaplan_test_bot');
        (new \ReflectionProperty(TelegramBot::class, 'id'))->setValue($bot, 5);
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bot->setTgUserId('555');
        $bot->setTgChatId('555');

        return $bot;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function update(array $message, int $fromId = 555, ?string $languageCode = null): array
    {
        return ['update_id' => 1, 'message' => $this->message($message, $fromId, $languageCode)];
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function edit(array $message, int $fromId = 555): array
    {
        return ['update_id' => 1, 'edited_message' => $this->message($message, $fromId, null)];
    }

    /**
     * @return array<string, mixed>
     */
    private function press(string $data, int $fromId = 555): array
    {
        return ['update_id' => 1, 'callback_query' => [
            'id' => 'cb-1',
            'from' => ['id' => $fromId],
            'data' => $data,
            'message' => ['message_id' => 1000, 'chat' => ['id' => 555, 'type' => 'private']],
        ]];
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function message(array $message, int $fromId, ?string $languageCode): array
    {
        $from = ['id' => $fromId];
        if (null !== $languageCode) {
            $from['language_code'] = $languageCode;
        }

        return $message + [
            'message_id' => 1,
            'from' => $from,
            'chat' => ['id' => $fromId, 'type' => 'private'],
        ];
    }
}
