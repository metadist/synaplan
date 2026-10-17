<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ThumbnailService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Support\MediaGenerationHandlerFactory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the media generation turn, MediaGenerationHandler::handleStream().
 *
 * Every way the turn's output leaves is recorded: the status events, the
 * stream chunks, the prompt extraction, the model lookups, what reaches the AI
 * facade (image, video, speech) and the background job bus, the downloads,
 * the registered file rows and thumbnails, the limit and budget checks, the
 * usage records, the memory job, the chat hand-off and the result. Every
 * collaborator that could leave the process is a double; the upload store is
 * real and writes into a temporary directory. handle() buffers the same turn.
 *
 * Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 */
final class MediaTurnCharacterizationTest extends TestCase
{
    private const SNAPSHOT = __DIR__.'/__snapshots__/media_turn.json';
    private const USER_ID = 7;
    private const MESSAGE_ID = 1001;
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';
    private const PUBLIC_BASE_URL = 'http://localhost:8000';

    /** The models of this install, by id: service, provider id, name, tag, settings, retired. */
    private const MODELS = [
        41 => ['OpenAI', 'gpt-image-1', 'GPT Image', 'text2pic', [], false],
        42 => ['OpenAI', 'gpt-image-1-edit', 'GPT Image Edit', 'pic2pic', [], false],
        43 => ['Google', 'veo-3', 'Veo 3', 'text2vid', ['default_duration' => 8], false],
        44 => ['Higgsfield', 'dop-turbo', 'DoP Turbo', 'img2vid', ['requires_reference_image' => true, 'default_duration' => 5], false],
        45 => ['OpenAI', 'tts-1', 'TTS 1', 'text2sound', [], false],
        46 => ['OpenAI', 'dall-e-2', 'DALL-E 2', 'text2pic', [], true],
    ];

    /** The defaults of this install, by capability. */
    private const DEFAULTS = ['TEXT2PIC' => 41, 'PIC2PIC' => 42, 'TEXT2VID' => 43, 'IMG2VID' => 44, 'TEXT2SOUND' => 45];

    private string $uploadDir = '';

    /** @var array<string, mixed> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-turn-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/attachments', 0o775, true);
        foreach (['photo.png', 'car.png'] as $name) {
            file_put_contents($this->uploadDir.'/attachments/'.$name, base64_decode(self::PIXEL));
        }
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->uploadDir);
    }

    public function testEveryMediaTurnMatchesTheSnapshot(): void
    {
        $actual = [];
        foreach (self::cases() as $id => $case) {
            $actual[$id] = $this->turn($case);
        }

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'Media turn');
    }

    public function testTheBufferedTurnAnswersLikeTheStreamedOne(): void
    {
        foreach (self::cases() as $id => $case) {
            $streamed = $this->turn($case);
            $buffered = $this->turn($case, buffered: true);

            $text = implode('', array_filter($streamed['chunks'], is_string(...)));
            self::assertSame([$text, $streamed['result']['metadata']], [$buffered['result']['content'], $buffered['result']['metadata']], $id);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function cases(): array
    {
        $png = 'data:image/png;base64,'.self::PIXEL;
        $image = static fn (string $url, string $model = 'gpt-image-1', ?string $size = null): array => [
            'images' => [array_filter(['url' => $url, 'size' => $size])],
            'provider' => 'openai',
            'model' => $model,
            'image_count' => 1,
        ];
        $video = ['videos' => [['url' => 'data:video/mp4;base64,'.base64_encode('not a real video')]], 'provider' => 'higgsfield', 'model' => 'dop-turbo', 'duration_seconds' => 5.0, 'resolution' => '720p'];

        return [
            'image_from_an_external_url' => [
                'text' => 'Draw a lighthouse at dusk',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'options' => ['record_media_usage' => true],
                'prompt' => ['prompt' => 'A lighthouse at dusk, oil painting', 'media_type' => 'image'],
                'ai' => ['generateImage' => $image('https://cdn.provider.test/out/lighthouse.png', size: '1024x1024')],
            ],
            'image_as_a_data_url' => [
                'text' => '/pic a red bicycle',
                'classification' => ['topic' => 'tools:pic', 'language' => 'en'],
                'prompt' => ['prompt' => 'A red bicycle', 'media_type' => 'image'],
                'ai' => ['generateImage' => $image($png)],
            ],
            'image_edit_from_an_attachment' => [
                'text' => 'Make the sky purple',
                'attachments' => ['attachments/photo.png'],
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'options' => ['record_media_usage' => true],
                'prompt' => ['prompt' => 'Make the sky purple', 'media_type' => 'image'],
                'ai' => ['generateImage' => $image($png, 'gpt-image-1-edit', '1536x1024')],
            ],
            'edit_of_the_last_picture_in_the_conversation' => [
                'text' => 'Mach das Auto blau',
                'classification' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'image', 'input_mode' => 'reference_images'],
                'edit_source' => 'attachments/car.png',
                'prompt' => ['prompt' => 'Paint the car blue', 'media_type' => 'image'],
                'ai' => ['generateImage' => $image($png, 'gpt-image-1-edit')],
            ],
            'image_url_in_the_text_becomes_a_video' => [
                'text' => 'Make a video from https://images.example.com/beach.jpg where the sun sets',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en'],
                'options' => ['record_media_usage' => true],
                'prompt' => ['prompt' => 'The sun sets over the beach', 'media_type' => 'video'],
                'ai' => ['generateVideo' => $video],
                'thumbnail' => true,
            ],
            'video_detached_to_a_background_job' => [
                'text' => 'A cat surfing a wave',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'video'],
                'options' => ['track_id' => 'track-9', 'node_id' => 'n2'],
                'prompt' => ['prompt' => 'A cat surfing a big wave', 'media_type' => 'video'],
                'async' => true,
            ],
            'speech_from_a_tts_command' => [
                'text' => '/tts Welcome aboard the harbour tour',
                'classification' => ['topic' => 'tools:tts', 'language' => 'en'],
                'options' => ['record_media_usage' => true],
                'prompt' => ['prompt' => 'Welcome aboard the harbour tour', 'media_type' => 'audio'],
                'ai' => ['synthesize' => ['relativePath' => '07/000/00007/tts/welcome.mp3', 'provider' => 'openai', 'model' => 'tts-1', 'text_length' => 31]],
            ],
            'a_tts_script_that_repeats_the_request_is_answered_as_chat' => [
                'text' => "Teach me the Persian numbers with a children's song",
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'audio', 'prompt_metadata' => ['aiModel' => 0]],
                'options' => ['resolved_prompt_data' => ['topic' => 'mediamaker']],
                'prompt' => ['prompt' => "Teach me the Persian numbers with a children's song", 'media_type' => 'audio'],
            ],
            'no_default_model' => [
                'text' => 'Draw a fox',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'defaults' => [],
            ],
            'text_to_video_on_an_image_to_video_model' => [
                'text' => 'Ein Drohnenflug über die Alpen',
                'classification' => ['topic' => 'mediamaker', 'language' => 'de', 'media_type' => 'video'],
                'prompt' => ['prompt' => 'A drone flight over the Alps', 'media_type' => 'video'],
                'defaults' => ['TEXT2VID' => 44] + self::DEFAULTS,
            ],
            'image_to_video_while_app_url_is_private' => [
                'text' => 'Animate this photo',
                'attachments' => ['attachments/photo.png'],
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'video'],
                'prompt' => ['prompt' => 'The photo comes alive', 'media_type' => 'video'],
            ],
            'rate_limited' => [
                'text' => 'Draw a fox',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'limit' => ['allowed' => false, 'used' => 5, 'limit' => 5],
            ],
            'over_budget' => [
                'text' => 'Draw a fox',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'budget' => ['allowed' => false, 'budget' => '20.00', 'used_cost' => '20.40', 'remaining' => '0.00'],
            ],
            'provider_failure' => [
                'text' => 'Draw a fox',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'ai' => ['generateImage' => new ProviderException('The prompt was rejected by the safety system', 'openai')],
            ],
            'download_failure' => [
                'text' => 'Draw a fox',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image'],
                'options' => ['record_media_usage' => true],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'ai' => ['generateImage' => $image('https://cdn.provider.test/out/fox.png')],
                'download_fails' => true,
            ],
            'stop_during_a_video' => [
                'text' => '/vid A rocket launch',
                'classification' => ['topic' => 'tools:vid', 'language' => 'en', 'duration' => 6],
                'options' => ['record_media_usage' => true, 'track_id' => 'track-3'],
                'prompt' => ['prompt' => 'A rocket launch at night', 'media_type' => 'video'],
                'ai' => ['generateVideo' => new ProviderCancelledException('The render was cancelled', 'google')],
            ],
            'retired_model_follows_its_successor' => [
                'text' => 'Draw a fox again',
                'classification' => ['topic' => 'mediamaker', 'language' => 'en', 'media_type' => 'image', 'model_id' => 46],
                'prompt' => ['prompt' => 'A fox in the snow', 'media_type' => 'image'],
                'replacement' => 41,
                'ai' => ['generateImage' => $image($png)],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private function turn(array $case, bool $buffered = false): array
    {
        $this->log = [
            'statuses' => [], 'chunks' => [], 'extractor' => [], 'models' => [], 'ai' => [], 'jobs' => [],
            'downloads' => [], 'files' => [], 'thumbnails' => [], 'limits' => [], 'usage' => [], 'memory' => [], 'chat' => [], 'flushes' => 0,
        ];
        $handler = $this->handler($case);
        $message = $this->message($case);
        $classification = $case['classification'];
        $options = $case['options'] ?? [];
        $progress = function (array $event): void {
            $this->log['statuses'][] = [$event['status'], $event['message'], $event['metadata']];
        };

        $result = $buffered
            ? $handler->handle($message, [], $classification, $progress, $options)
            : $handler->handleStream($message, [], $classification, function (string|array $chunk): void {
                $this->log['chunks'][] = $chunk;
            }, $progress, $options);

        return $this->normalized($this->log + ['result' => $result]);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function handler(array $case): MediaGenerationHandler
    {
        return MediaGenerationHandlerFactory::build([
            'ai' => $this->ai($case),
            'models' => $this->models($case),
            'em' => $this->entityManager(),
            'extractor' => $this->extractor($case),
            'downloader' => $this->downloader($case),
            'thumbnails' => $this->thumbnails($case),
            'limits' => $this->limits($case),
            'memory' => $this->memory(),
            'cancellation' => $this->createStub(MediaCancellationStore::class),
            'jobConfig' => $this->jobConfig($case),
            'jobs' => $this->jobs(),
            'jobDispatcher' => $this->jobDispatcher(),
            'jobSync' => $this->createStub(MediaJobMessageSync::class),
            'registrar' => $this->registrar(),
            'gate' => $this->gate($case),
            'catalog' => $this->catalog($case),
            'uploadDir' => $this->uploadDir,
            'publicBaseUrl' => self::PUBLIC_BASE_URL,
            'folders' => $this->createStub(RequestedFolderDelivery::class),
            'chat' => $this->chat(),
        ]);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function ai(array $case): AiFacade
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('supportsInlineReferenceImage')->willReturn(false);
        $ai->method('supportsAsyncVideo')->willReturn(true);
        foreach (['generateImage', 'generateVideo', 'synthesize'] as $method) {
            $ai->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method, $case): array {
                $this->log['ai'][] = [$method, ...$arguments];
                $answer = $case['ai'][$method] ?? throw new \LogicException(sprintf('The case has no answer for %s().', $method));
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            });
        }

        return $ai;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function models(array $case): ModelConfigService
    {
        $defaults = $case['defaults'] ?? self::DEFAULTS;
        $models = $this->createStub(ModelConfigService::class);
        $models->method('getEffectiveUserIdForMessage')->willReturn(self::USER_ID);
        $models->method('getDefaultModel')->willReturnCallback(function (string $capability) use ($defaults): ?int {
            $this->log['models'][] = ['default', $capability];

            return $defaults[$capability] ?? null;
        });
        $models->method('replacementForRetiredModel')->willReturnCallback(function (int $modelId, string $capability) use ($case): ?int {
            $this->log['models'][] = ['replacement', $modelId, $capability];

            return $case['replacement'] ?? null;
        });

        return $models;
    }

    private function entityManager(): EntityManagerInterface
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER_ID);
        $user->method('isMemoriesEnabled')->willReturn(true);
        $users = $this->createStub(EntityRepository::class);
        $users->method('find')->willReturn($user);

        $models = $this->createStub(EntityRepository::class);
        $models->method('find')->willReturnCallback(function (mixed $id): ?Model {
            if (!isset(self::MODELS[(int) $id])) {
                return null;
            }
            [$service, $providerId, $name, $tag, $settings, $retired] = self::MODELS[(int) $id];
            $model = $this->createStub(Model::class);
            $model->method('getService')->willReturn($service);
            $model->method('getProviderId')->willReturn($providerId);
            $model->method('getName')->willReturn($name);
            $model->method('getTag')->willReturn($tag);
            $model->method('getJson')->willReturn($settings);
            $model->method('isRetired')->willReturn($retired);

            return $model;
        });

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([[User::class, $users], [Model::class, $models]]);
        $em->method('flush')->willReturnCallback(function (): void {
            ++$this->log['flushes'];
        });

        return $em;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function extractor(array $case): MediaPromptExtractor
    {
        $extractor = $this->createStub(MediaPromptExtractor::class);
        $extractor->method('extract')->willReturnCallback(function (Message $message, array $thread, array $classification, array $context = []) use ($case): array {
            $this->log['extractor'][] = $context;

            return $case['prompt'];
        });

        return $extractor;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function downloader(array $case): MediaDownloader
    {
        $downloader = $this->createStub(MediaDownloader::class);
        $downloader->method('fetch')->willReturnCallback(function (string $url) use ($case): string {
            $this->log['downloads'][] = $url;
            if (!empty($case['download_fails'])) {
                throw new \Exception('cURL download failed (HTTP 404): ');
            }

            return base64_decode(self::PIXEL);
        });

        return $downloader;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function thumbnails(array $case): ThumbnailService
    {
        $thumbnails = $this->createStub(ThumbnailService::class);
        $thumbnails->method('generateThumbnail')->willReturnCallback(function (string $videoPath) use ($case): ?string {
            $this->log['thumbnails'][] = $videoPath;

            return empty($case['thumbnail']) ? null : $videoPath.'.jpg';
        });

        return $thumbnails;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function limits(array $case): RateLimitService
    {
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturnCallback(function (User $user, string $action) use ($case): array {
            $this->log['limits'][] = ['limit', $action];

            return $case['limit'] ?? ['allowed' => true, 'used' => 0, 'limit' => 50];
        });
        $limits->method('checkCostBudget')->willReturnCallback(function () use ($case): array {
            $this->log['limits'][] = ['budget'];

            return $case['budget'] ?? ['allowed' => true];
        });
        $limits->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata): RecordedUsage {
            $this->log['usage'][] = [$action, $metadata];

            return new RecordedUsage('0.040000', '0.040000', 0, 0, 0);
        });

        return $limits;
    }

    private function memory(): PendingMemoryExtraction
    {
        $memory = $this->createStub(PendingMemoryExtraction::class);
        $memory->method('build')->willReturnCallback(function (Message $message, string $answer, array $thread, array $memories, bool $disabledByRequest, bool $disabledByUser, array $context = [], bool $incognito = false): null {
            $this->log['memory'][] = ['build', $answer, $disabledByRequest, $disabledByUser, $incognito];

            return null;
        });
        $memory->method('dispatch')->willReturnCallback(function (): void {
            $this->log['memory'][] = ['dispatch'];
        });

        return $memory;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function jobConfig(array $case): MediaJobConfig
    {
        $config = $this->createStub(MediaJobConfig::class);
        $config->method('isAsyncJobsEnabled')->willReturn(!empty($case['async']));
        $config->method('maxActiveJobsPerUser')->willReturn(16);

        return $config;
    }

    private function jobs(): MediaJobService
    {
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('countActiveForUser')->willReturn(0);
        $jobs->method('create')->willReturnCallback(function (array $params): MediaJob {
            $this->log['jobs'][] = ['create', $params];

            return new MediaJob('media-job-1');
        });

        return $jobs;
    }

    private function jobDispatcher(): MediaJobDispatcher
    {
        $dispatcher = $this->createStub(MediaJobDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (MediaJob $job): bool {
            $this->log['jobs'][] = ['dispatch', $job->getJobKey()];

            return true;
        });

        return $dispatcher;
    }

    private function registrar(): GeneratedFileRegistrar
    {
        $registrar = $this->createStub(GeneratedFileRegistrar::class);
        $registrar->method('register')->willReturnCallback(function (int $userId, ?string $relativePath, string $type, ?int $messageId = null, ?string $provider = null, bool $ephemeral = false, ?string $fileText = null): File {
            $this->log['files'][] = [$userId, $relativePath, $type, $messageId, $provider, $ephemeral, $fileText];

            return $this->createStub(File::class);
        });

        return $registrar;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function gate(array $case): PremiumFeatureGate
    {
        $gate = $this->createStub(PremiumFeatureGate::class);
        $gate->method('isCostBudgetEnforced')->willReturn(isset($case['budget']));

        return $gate;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function catalog(array $case): ConversationFileCatalog
    {
        $source = isset($case['edit_source'])
            ? new ConversationFile('file:91', basename($case['edit_source']), ConversationFile::CATEGORY_IMAGE, 'generated', $this->uploadDir.'/'.$case['edit_source'], $case['edit_source'], 91)
            : null;
        $catalog = $this->createStub(ConversationFileCatalog::class);
        $catalog->method('build')->willReturn([]);
        $catalog->method('latestImage')->willReturn($source);

        return $catalog;
    }

    private function chat(): ChatHandler
    {
        $chat = $this->createStub(ChatHandler::class);
        $chat->method('handleStream')->willReturnCallback(function (Message $message, array $thread, array $classification, callable $stream, ?callable $progress = null, array $options = []): array {
            $this->log['chat'][] = [$classification, $options];
            $stream('Yek, do, se — here is a song to count along.');

            return ['metadata' => ['provider' => 'groq', 'model' => 'llama-3.3-70b']];
        });

        return $chat;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function message(array $case): Message
    {
        $files = [];
        foreach ($case['attachments'] ?? [] as $path) {
            $file = $this->createStub(File::class);
            $file->method('getFilePath')->willReturn($path);
            $files[] = $file;
        }

        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(self::USER_ID);
        $message->method('getId')->willReturn(self::MESSAGE_ID);
        $message->method('getText')->willReturn($case['text']);
        $message->method('getLanguage')->willReturn($case['classification']['language']);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection($files));
        $message->method('getMeta')->willReturn(null);

        return $message;
    }

    /**
     * The upload directory, the month and the timestamps in file names vary
     * per run; callables and objects are named, not dumped.
     */
    private function normalized(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->normalized(...), $value);
        }
        if ($value instanceof \Closure) {
            return '<callable>';
        }
        if (is_object($value)) {
            return '<'.(new \ReflectionClass($value))->getShortName().'>';
        }
        if (!is_string($value)) {
            return $value;
        }

        $value = str_replace([$this->uploadDir, '/'.date('Y').'/'.date('m').'/'], ['<uploads>', '/<year>/<month>/'], $value);

        return (string) preg_replace(['/_\d{10}\./', '/_\d{10}_[0-9a-f]{12}\./'], ['_<time>.', '_<time>_<random>.'], $value);
    }
}
