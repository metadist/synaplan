<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\SharedChatPageController;
use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Service\Branding\BrandingService;
use App\Service\File\OgImageService;
use App\Service\Message\GeneratedMediaTextRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * Shared page title/description must never use a media prompt as the preview.
 */
final class SharedChatPageControllerMediaTitleTest extends TestCase
{
    public function testTitleUsesChatTitleWhenFirstAnswerIsImage(): void
    {
        $controller = $this->controller();
        $chat = new Chat();
        $chat->setTitle('Weekend photos');

        $imageOut = new Message();
        $imageOut->setDirection('OUT');
        $imageOut->setText('__IMAGE_GENERATED__');

        $method = new \ReflectionMethod(SharedChatPageController::class, 'generateTitle');
        $method->setAccessible(true);

        $title = $method->invoke($controller, $chat, [$imageOut]);
        self::assertStringContainsString('Weekend photos', $title);
        self::assertStringNotContainsString('__IMAGE_GENERATED__', $title);
    }

    public function testTitleSkipsLegacyGeneratedImagePromptWhenChatUntitled(): void
    {
        $controller = $this->controller();
        $chat = new Chat();
        $chat->setTitle('New Chat');

        $legacy = new Message();
        $legacy->setDirection('OUT');
        $legacy->setText('Generated image: a blue lake at dawn with mountains');

        $followUp = new Message();
        $followUp->setDirection('OUT');
        $followUp->setText('Here is a short note about the photo.');

        $method = new \ReflectionMethod(SharedChatPageController::class, 'generateTitle');
        $method->setAccessible(true);
        $title = $method->invoke($controller, $chat, [$legacy, $followUp]);

        self::assertStringContainsString('Here is a short note about the photo', $title);
        self::assertStringNotContainsString('blue lake', $title);
        self::assertStringNotContainsString('Generated ', $title);
    }

    private function controller(): SharedChatPageController
    {
        $branding = $this->createStub(BrandingService::class);
        $branding->method('getBranding')->willReturn(['name' => BrandingService::DEFAULT_NAME]);

        return new SharedChatPageController(
            $this->createStub(ChatRepository::class),
            $this->createStub(MessageRepository::class),
            $this->createStub(OgImageService::class),
            $branding,
            'https://example.test',
            $this->renderer(),
        );
    }

    public function testDescriptionUsesUserMessageNotMediaPrompt(): void
    {
        $controller = $this->controller();

        $userIn = new Message();
        $userIn->setDirection('IN');
        $userIn->setText('Draw a cat');

        $imageOut = new Message();
        $imageOut->setDirection('OUT');
        $imageOut->setText('Generated image: fluffy orange cat sitting on a windowsill');

        $method = new \ReflectionMethod(SharedChatPageController::class, 'generateDescription');
        $method->setAccessible(true);
        $description = $method->invoke($controller, [$userIn, $imageOut]);

        self::assertSame('Draw a cat', $description);
        self::assertStringNotContainsString('fluffy orange', $description);
    }

    private function renderer(): GeneratedMediaTextRenderer
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'image_generated' => 'Here is the image you asked for.',
            'video_generated' => 'Here is the video you asked for.',
            'audio_generated' => 'Here is the audio you asked for.',
            'file_generated' => "I created the file '{filename}' for you.",
            'file_generation_failed' => 'The file could not be created.',
        ], 'en', 'generated_media');

        return new GeneratedMediaTextRenderer($translator);
    }
}
