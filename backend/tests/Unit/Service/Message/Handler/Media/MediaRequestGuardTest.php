<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaModelChoice;
use App\Service\Message\Handler\Media\MediaPrompt;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaRequestGuard;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\Message\Handler\Media\MediaUploadStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaRequestGuardTest extends TestCase
{
    private const REQUEST = "Teach me the Persian numbers with a children's song";

    public function testOnlyASorterRoutedAudioScriptThatRepeatsTheRequestIsAnsweredAsChat(): void
    {
        self::assertSame(
            [true, false, false, false, false],
            [
                $this->answersAsChat('audio', false, self::REQUEST),
                $this->answersAsChat('audio', true, self::REQUEST),
                $this->answersAsChat('audio', false, self::REQUEST, override: 'Read this aloud'),
                $this->answersAsChat('audio', false, 'Welcome aboard the harbour tour'),
                $this->answersAsChat('image', false, self::REQUEST),
            ],
        );
    }

    public function testATurnWithoutAModelIsToldToConfigureOne(): void
    {
        $refusal = $this->guard()->refusal($this->request('image', new MediaModel(null, null, null, []), language: 'de'));
        self::assertNotNull($refusal);

        self::assertSame(['error' => 'no_media_model_configured', 'media_type' => 'image'], $refusal->metadata);
        self::assertStringStartsWith('Für die Medienerstellung ist kein Modell konfiguriert.', $refusal->text);
    }

    public function testTextToVideoOnAnImageToVideoModelIsRefused(): void
    {
        foreach ([['requires_reference_image' => true], ['features' => ['image2video']]] as $config) {
            $refusal = $this->guard()->refusal($this->request('video', new MediaModel(44, 'higgsfield', 'dop-turbo', $config)));
            self::assertNotNull($refusal);

            self::assertSame(['error' => 'text2vid_requires_reference_image', 'provider' => 'higgsfield', 'model' => 'dop-turbo'], $refusal->metadata);
        }
    }

    public function testImageToVideoNeedsAnAppUrlTheProviderCanReach(): void
    {
        $model = new MediaModel(44, 'higgsfield', 'dop-turbo', ['requires_reference_image' => true]);
        $attached = new MediaReferences(['/uploads/photo.png'], [], null);
        $linked = new MediaReferences(['/uploads/photo.png'], ['https://images.example.com/beach.jpg'], null);

        $refusal = $this->guard()->refusal($this->request('video', $model, $attached));

        self::assertNotNull($refusal);
        self::assertSame(['error' => 'i2v_requires_public_app_url', 'provider' => 'higgsfield', 'model' => 'dop-turbo'], $refusal->metadata);
        self::assertStringContainsString('APP_URL = "http://localhost:8000"', $refusal->text);

        self::assertNull($this->guard()->refusal($this->request('video', $model, $linked)));
        self::assertNull($this->guard(inlineProvider: true)->refusal($this->request('video', $model, $attached)));
        self::assertNull($this->guard(appUrl: 'https://web.synaplan.com')->refusal($this->request('video', $model, $attached)));
    }

    public function testATurnThatCannotTellItsMediaTypeAsksForACommand(): void
    {
        $refusal = $this->guard()->undeterminedMediaType(['language' => 'en']);

        self::assertSame(['error' => 'media_type_not_determined'], $refusal->metadata);
        self::assertStringContainsString('`/pic` for images, `/vid` for videos, `/tts` for audio', $refusal->text);
    }

    private function answersAsChat(string $mediaType, bool $isSlashCommand, string $script, ?string $override = null): bool
    {
        $message = $this->createStub(Message::class);
        $message->method('getText')->willReturn(self::REQUEST);
        $message->method('getMeta')->willReturnCallback(static fn (string $key): ?string => 'media_prompt_override' === $key ? $override : null);

        return $this->guard()->answersAsChat(
            new MediaModelChoice(45, $mediaType, null, null, $isSlashCommand, new MediaReferences([], [], null)),
            new MediaPrompt($script, $mediaType, new MediaReferences([], [], null)),
            $message,
        );
    }

    private function guard(bool $inlineProvider = false, string $appUrl = 'http://localhost:8000'): MediaRequestGuard
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('supportsInlineReferenceImage')->willReturn($inlineProvider);

        return new MediaRequestGuard(
            $ai,
            new MediaUploadStore(new UserUploadPathBuilder(), $this->createStub(MediaDownloader::class), new NullLogger(), sys_get_temp_dir(), $appUrl),
            new NullLogger(),
            $appUrl,
        );
    }

    private function request(string $mediaType, MediaModel $model, ?MediaReferences $references = null, string $language = 'en'): MediaTurnRequest
    {
        return new MediaTurnRequest($this->createStub(Message::class), [], ['language' => $language], [], 'A drone flight', $mediaType, $references ?? new MediaReferences([], [], null), $model, 7);
    }
}
