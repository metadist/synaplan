<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Service\Telegram\TelegramCopy;
use App\Service\Telegram\TelegramIncoming;
use App\Service\Telegram\TelegramMediaRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class TelegramIncomingTest extends TestCase
{
    public function testTheCaptionIsTheQuestionAboutTheFile(): void
    {
        $incoming = self::parse(['message_id' => 3, 'caption' => ' What is this? ', 'document' => ['file_id' => 'd', 'file_name' => 'a.pdf']]);

        $this->assertSame('What is this?', $incoming->prompt());
        $this->assertSame(TelegramMediaRef::DOCUMENT, $incoming->media?->kind);
        $this->assertSame('a.pdf', $incoming->media->fileName);
        $this->assertSame('3', $incoming->messageId);
    }

    public function testTheLargestPhotoTelegramStillHandsOutIsUsed(): void
    {
        $incoming = self::parse(['photo' => [
            ['file_id' => 's', 'file_size' => 100],
            ['file_id' => 'm', 'file_size' => 1000],
            ['file_id' => 'huge', 'file_size' => 30_000_000],
        ]]);

        $this->assertSame('m', $incoming->media?->fileId);
    }

    public function testAMovingStickerIsReadThroughItsThumbnail(): void
    {
        $incoming = self::parse(['sticker' => [
            'file_id' => 'animated',
            'is_animated' => true,
            'emoji' => '😂',
            'thumbnail' => ['file_id' => 'thumb'],
        ]]);

        $this->assertSame('thumb', $incoming->media?->fileId);
        $this->assertStringStartsWith('[Sticker 😂]', $incoming->prompt());
    }

    public function testAVoiceMessageHasNoPromptUntilItIsTranscribed(): void
    {
        $incoming = self::parse(['voice' => ['file_id' => 'v']]);

        $this->assertSame('', $incoming->prompt());
        $this->assertTrue($incoming->media?->isSpoken());
        $this->assertFalse($incoming->isEmpty());
    }

    public function testALiveLocationIsNamedAsSuch(): void
    {
        $incoming = self::parse(['location' => ['latitude' => 48.1374, 'longitude' => 11.5755, 'live_period' => 900]]);

        $this->assertStringStartsWith('Shared live location: 48.1374, 11.5755', $incoming->prompt());
        $this->assertSame(['location' => ['latitude' => 48.1374, 'longitude' => 11.5755, 'live_period' => 900]], $incoming->payload);
    }

    public function testAQuizNamesTheCorrectAnswer(): void
    {
        $incoming = self::parse(['poll' => [
            'type' => 'quiz',
            'question' => 'Capital of France?',
            'options' => [['text' => 'Rome'], ['text' => 'Paris']],
            'correct_option_id' => 1,
        ]]);

        $this->assertSame("Shared quiz: Capital of France?\n1. Rome\n2. Paris (correct answer)", $incoming->prompt());
    }

    public function testAContactAndDiceBecomeText(): void
    {
        $contact = self::parse(['contact' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone_number' => '+44 1']]);
        $dice = self::parse(['dice' => ['emoji' => '🎯', 'value' => 6]]);

        $this->assertSame('Shared contact: Ada Lovelace, +44 1', $contact->prompt());
        $this->assertSame('Rolled 🎯: 6', $dice->prompt());
    }

    public function testAGameIsNothingWeCanRead(): void
    {
        $incoming = self::parse(['game' => ['title' => 'x']]);

        $this->assertTrue($incoming->isEmpty());
    }

    public function testAnAlbumOfPhotosAsksForAllOfThem(): void
    {
        $photo = new TelegramMediaRef(TelegramMediaRef::PHOTO, 'a', null, null, null);
        $document = new TelegramMediaRef(TelegramMediaRef::DOCUMENT, 'b', null, null, null);

        $this->assertSame('Describe what you see in these images.', TelegramIncoming::albumPrompt([$photo, $photo], self::say()));
        $this->assertSame('Summarize these files.', TelegramIncoming::albumPrompt([$photo, $document], self::say()));
    }

    public function testTheQuestionAboutAFileFollowsTheLanguage(): void
    {
        $incoming = TelegramIncoming::fromMessage(['document' => ['file_id' => 'd']], self::say('fr'));

        $this->assertSame('Résume ce fichier.', $incoming->prompt());
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function parse(array $message): TelegramIncoming
    {
        return TelegramIncoming::fromMessage($message, self::say());
    }

    /**
     * @return callable(string, array<string, string|int>): string
     */
    private static function say(string $locale = 'en'): callable
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'fr'] as $language) {
            $translator->addResource('yaml', dirname(__DIR__, 4).'/translations/telegram.'.$language.'.yaml', $language, 'telegram');
        }

        return (new TelegramCopy($translator))->sayer($locale);
    }
}
