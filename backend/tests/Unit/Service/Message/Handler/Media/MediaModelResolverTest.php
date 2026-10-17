<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Entity\Model;
use App\Service\File\ConversationFile;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaModelChoice;
use App\Service\Message\Handler\Media\MediaModelResolver;
use App\Service\Message\Handler\Media\MediaPrompt;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaModelResolverTest extends TestCase
{
    /** The models of this install, by id: service, provider id, name, tag, retired. */
    private const MODELS = [
        41 => ['OpenAI', 'gpt-image-1', 'GPT Image', 'text2pic', false],
        43 => ['Google', 'veo-3', 'Veo 3', 'text2vid', false],
        46 => ['OpenAI', 'dall-e-2', 'DALL-E 2', 'text2pic', true],
    ];

    private const DEFAULTS = ['TEXT2PIC' => 41, 'PIC2PIC' => 42, 'TEXT2VID' => 43, 'IMG2VID' => 44, 'TEXT2SOUND' => 45];

    /** @var list<string> */
    private array $defaultsAsked = [];

    public function testAnAgainPickWinsOverTheTaskPromptAndSetsTheMediaTypeByItsTag(): void
    {
        $choice = $this->choose(['model_id' => 43, 'prompt_metadata' => ['aiModel' => 41]], $this->prompt('image'));

        self::assertSame([43, 'video', 'Google', 'Veo 3'], [$choice?->modelId, $choice?->mediaType, $choice?->provider, $choice?->modelName]);
        self::assertSame([], $this->defaultsAsked);
    }

    public function testASlashCommandKeepsItsMediaTypeWhateverThePickedModel(): void
    {
        $choice = $this->choose(['topic' => 'tools:pic', 'model_id' => 43], $this->prompt('video'));

        self::assertSame([43, 'image', null, null, true], [$choice?->modelId, $choice?->mediaType, $choice?->provider, $choice?->modelName, $choice?->isSlashCommand]);
    }

    /**
     * @param array<string, mixed> $classification
     */
    #[DataProvider('requests')]
    public function testTheInstallsDefaultFollowsWhatTheRequestNeeds(array $classification, MediaPrompt $prompt, string $capability, string $mediaType): void
    {
        $choice = $this->choose($classification, $prompt);

        self::assertSame([[$capability], self::DEFAULTS[$capability], $mediaType], [$this->defaultsAsked, $choice?->modelId, $choice?->mediaType]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, MediaPrompt, string, string}>
     */
    public static function requests(): iterable
    {
        $attached = new MediaReferences(['/uploads/photo.png'], [], null);
        $linked = new MediaReferences([], ['https://images.example.com/beach.jpg'], null);

        yield 'a picture' => [[], self::prompt('image'), 'TEXT2PIC', 'image'];
        yield 'an edit of an attached picture' => [[], self::prompt('image', $attached), 'PIC2PIC', 'image'];
        yield 'an attached picture brought to life' => [[], self::prompt('video', $attached), 'IMG2VID', 'video'];
        yield 'a linked picture brought to life' => [[], self::prompt('video', $linked), 'IMG2VID', 'video'];
        yield 'a video' => [[], self::prompt('video'), 'TEXT2VID', 'video'];
        yield 'speech' => [[], self::prompt('audio'), 'TEXT2SOUND', 'audio'];
        yield '/vid with a picture' => [['topic' => 'tools:vid'], self::prompt('image', $linked), 'IMG2VID', 'video'];
        yield '/vid' => [['topic' => 'tools:vid'], self::prompt(null), 'TEXT2VID', 'video'];
        yield '/tts' => [['topic' => 'tools:tts'], self::prompt(null), 'TEXT2SOUND', 'audio'];
        yield '/pic with a picture' => [['topic' => 'tools:pic'], self::prompt('video', $attached), 'TEXT2PIC', 'image'];
    }

    public function testAConversationEditWithoutAPic2picDefaultDrawsFromText(): void
    {
        $source = new ConversationFile('file:9', 'car.png', ConversationFile::CATEGORY_IMAGE, ConversationFile::ORIGIN_GENERATED, '/uploads/car.png', 'car.png', 9);
        $choice = $this->choose([], $this->prompt('image', new MediaReferences(['/uploads/car.png'], [], $source)), ['PIC2PIC' => null] + self::DEFAULTS);

        self::assertSame([['PIC2PIC', 'TEXT2PIC'], 41], [$this->defaultsAsked, $choice?->modelId]);
        self::assertEquals(new MediaReferences([], [], null), $choice?->references);
    }

    public function testAnAttachedPictureWithoutAPic2picDefaultStaysWithoutAModel(): void
    {
        $choice = $this->choose([], $this->prompt('image', new MediaReferences(['/uploads/photo.png'], [], null)), ['PIC2PIC' => null] + self::DEFAULTS);

        self::assertSame([['PIC2PIC'], null, ['/uploads/photo.png']], [$this->defaultsAsked, $choice?->modelId, $choice?->references->attachedImagePaths]);
    }

    public function testWithoutAMediaTypeTheTurnHasNoChoice(): void
    {
        self::assertNull($this->choose([], $this->prompt(null)));
    }

    public function testARetiredModelFollowsItsSuccessor(): void
    {
        $model = $this->resolver(replacement: 41)->resolve($this->createStub(Message::class), new MediaModelChoice(46, 'image', 'OpenAI', 'DALL-E 2', false, new MediaReferences([], [], null)));

        self::assertEquals(new MediaModel(41, 'openai', 'gpt-image-1', []), $model);
    }

    public function testARetiredModelWithoutASuccessorLeavesNoProvider(): void
    {
        $model = $this->resolver(replacement: null)->resolve($this->createStub(Message::class), new MediaModelChoice(46, 'image', 'OpenAI', 'DALL-E 2', false, new MediaReferences([], [], null)));

        self::assertEquals(new MediaModel(null, null, null, []), $model);
    }

    /**
     * @param array<string, mixed>    $classification
     * @param array<string, int|null> $defaults
     */
    private function choose(array $classification, MediaPrompt $prompt, array $defaults = self::DEFAULTS): ?MediaModelChoice
    {
        return $this->resolver(defaults: $defaults)->choose($this->createStub(Message::class), $classification, $prompt);
    }

    /**
     * @param array<string, int|null> $defaults
     */
    private function resolver(array $defaults = self::DEFAULTS, ?int $replacement = null): MediaModelResolver
    {
        $config = $this->createStub(ModelConfigService::class);
        $config->method('getEffectiveUserIdForMessage')->willReturn(7);
        $config->method('getDefaultModel')->willReturnCallback(function (string $capability) use ($defaults): ?int {
            $this->defaultsAsked[] = $capability;

            return $defaults[$capability] ?? null;
        });
        $config->method('replacementForRetiredModel')->willReturnCallback(static fn (int $modelId, string $capability, ?int $userId): ?int => 46 === $modelId && 'TEXT2PIC' === $capability && 7 === $userId ? $replacement : null);

        $models = $this->createStub(EntityRepository::class);
        $models->method('find')->willReturnCallback(function (mixed $id): ?Model {
            if (!isset(self::MODELS[(int) $id])) {
                return null;
            }
            [$service, $providerId, $name, $tag, $retired] = self::MODELS[(int) $id];
            $model = $this->createStub(Model::class);
            $model->method('getService')->willReturn($service);
            $model->method('getProviderId')->willReturn($providerId);
            $model->method('getName')->willReturn($name);
            $model->method('getTag')->willReturn($tag);
            $model->method('getJson')->willReturn([]);
            $model->method('isRetired')->willReturn($retired);

            return $model;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($models);

        return new MediaModelResolver($config, $em, new NullLogger());
    }

    private static function prompt(?string $mediaType, ?MediaReferences $references = null): MediaPrompt
    {
        return new MediaPrompt('A fox in the snow', $mediaType, $references ?? new MediaReferences([], [], null));
    }
}
