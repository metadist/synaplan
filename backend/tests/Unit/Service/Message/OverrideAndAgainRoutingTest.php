<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Entity\Message;
use App\Entity\MessageMeta;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPinResolver;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\Processing\TurnClassificationResolver;
use App\Service\Message\Processing\TurnStatus;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where a model the user picked sends a message, through both entries that
 * ask: a model override in MessageClassifier and an Again in
 * TurnClassificationResolver. One row per model tag (null: no tag) and topic
 * already on the message, with the topic, intent and model both entries
 * answer: the picked model, or none when the handler picks its own.
 */
final class OverrideAndAgainRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: array{0: string, 1: string, 2: ?int}}>
     */
    public static function rows(): iterable
    {
        $rows = [
            // model tag, topic on the message, [topic, intent, model] both entries answer
            ['text2pic', '', ['mediamaker', 'image_generation', 42]],
            ['text2pic', 'mediamaker', ['mediamaker', 'image_generation', 42]],
            ['text2vid', '', ['mediamaker', 'image_generation', 42]],
            ['text2vid', 'mediamaker', ['mediamaker', 'image_generation', 42]],
            ['text2sound', '', ['mediamaker', 'image_generation', 42]],
            ['text2sound', 'mediamaker', ['mediamaker', 'image_generation', 42]],
            ['pic2text', '', ['general', 'chat', 42]],
            ['pic2text', 'mediamaker', ['general', 'chat', 42]],
            ['analyze', '', ['general', 'chat', 42]],
            ['analyze', 'mediamaker', ['general', 'chat', 42]],
            ['vision', '', ['general', 'chat', 42]],
            ['vision', 'mediamaker', ['general', 'chat', 42]],
            ['document', '', ['officemaker', 'document_generation', 42]],
            ['document', 'mediamaker', ['officemaker', 'document_generation', 42]],
            ['officemaker', '', ['officemaker', 'document_generation', 42]],
            ['officemaker', 'mediamaker', ['officemaker', 'document_generation', 42]],
            ['text2doc', '', ['officemaker', 'document_generation', 42]],
            ['text2doc', 'mediamaker', ['officemaker', 'document_generation', 42]],
            ['chat', '', ['general', 'chat', 42]],
            ['chat', 'UNKNOWN', ['general', 'chat', 42]],
            ['chat', 'CHAT', ['general', 'chat', 42]],
            ['chat', 'CONTINUE', ['general', 'chat', 42]],
            ['chat', 'chat', ['chat', 'chat', 42]],
            ['chat', 'legal-review', ['legal-review', 'chat', 42]],
            ['chat', 'mediamaker', ['mediamaker', 'image_generation', null]],
            ['chat', 'tools:pic', ['tools:pic', 'image_generation', null]],
            ['vectorize', '', ['general', 'chat', 42]],
            ['vectorize', 'UNKNOWN', ['general', 'chat', 42]],
            ['vectorize', 'legal-review', ['legal-review', 'chat', 42]],
            ['vectorize', 'mediamaker', ['mediamaker', 'image_generation', null]],
            ['vectorize', 'tools:pic', ['tools:pic', 'image_generation', null]],
            ['rerank', '', ['general', 'chat', 42]],
            ['rerank', 'unknown', ['general', 'chat', 42]],
            ['rerank', 'UNKNOWN', ['general', 'chat', 42]],
            ['rerank', 'CHAT', ['general', 'chat', 42]],
            ['rerank', 'legal-review', ['legal-review', 'chat', 42]],
            ['rerank', 'mediamaker', ['mediamaker', 'image_generation', null]],
            ['rerank', 'tools:pic', ['tools:pic', 'image_generation', null]],
            [null, '', ['general', 'chat', 42]],
            [null, 'CHAT', ['general', 'chat', 42]],
            [null, 'legal-review', ['legal-review', 'chat', 42]],
        ];

        foreach ($rows as $row) {
            yield sprintf('%s on "%s"', $row[0] ?? 'no tag', $row[1]) => $row;
        }
    }

    /**
     * @param array{0: string, 1: string, 2: ?int} $routed
     */
    #[DataProvider('rows')]
    public function testAModelOverrideAndAnAgainRouteTheTagAndTopicAlike(?string $tag, string $topic, array $routed): void
    {
        $byOverride = $this->classifier($tag)->classify($this->message($topic));
        self::assertSame($routed, [$byOverride['topic'], $byOverride['intent'], $byOverride['model_id']], 'model override');

        $byAgain = $this->resolver($tag)->againForBufferedTurn($this->message($topic), ['is_again' => true, 'model_id' => 42], new TurnStatus(null, new NullLogger()));
        self::assertSame($routed, [$byAgain['topic'], $byAgain['intent'], $byAgain['model_id']], 'Again');
    }

    public function testAnAgainOfAPicMessageRegeneratesTheImage(): void
    {
        $byAgain = $this->resolver('chat')->againForBufferedTurn($this->message('tools:pic'), ['is_again' => true, 'model_id' => 42], new TurnStatus(null, new NullLogger()));

        self::assertSame(['tools:pic', 'image_generation'], [$byAgain['topic'], $byAgain['intent']]);
    }

    public function testADocumentModelOverrideReachesTheDocumentHandler(): void
    {
        $byOverride = $this->classifier('document')->classify($this->message(''));

        self::assertSame(['officemaker', 'document_generation'], [$byOverride['topic'], $byOverride['intent']]);
    }

    public function testAnUnmappedModelOverrideOnANewMessageGoesToGeneral(): void
    {
        $byOverride = $this->classifier('rerank')->classify($this->message('UNKNOWN'));

        self::assertSame(['general', 'chat'], [$byOverride['topic'], $byOverride['intent']]);
    }

    public function testAChatModelOverrideKeepsTheCustomPromptTopic(): void
    {
        $byOverride = $this->classifier('chat')->classify($this->message('legal-review'));

        self::assertSame(['legal-review', 'chat'], [$byOverride['topic'], $byOverride['intent']]);
    }

    public function testAVisionModelOverrideOnAMediaMessageAnswersAsChat(): void
    {
        $byOverride = $this->classifier('vision')->classify($this->message('mediamaker'));

        self::assertSame(['general', 'chat'], [$byOverride['topic'], $byOverride['intent']]);
    }

    public function testAChatModelRetryOfAnUnclassifiedMessageAnswersOnGeneral(): void
    {
        foreach (['CHAT', 'CONTINUE'] as $placeholder) {
            $byOverride = $this->classifier('chat')->classify($this->message($placeholder));
            $byAgain = $this->resolver('chat')->againForBufferedTurn($this->message($placeholder), ['is_again' => true, 'model_id' => 42], new TurnStatus(null, new NullLogger()));

            self::assertSame(['general', 'general'], [$byOverride['topic'], $byAgain['topic']], $placeholder);
        }
    }

    public function testAChatModelRetryOfAMediaMessageLeavesTheModelToTheMediaHandler(): void
    {
        foreach (['mediamaker', 'tools:pic'] as $topic) {
            $byOverride = $this->classifier('chat')->classify($this->message($topic));
            $byAgain = $this->resolver('chat')->againForBufferedTurn($this->message($topic), ['is_again' => true, 'model_id' => 42], new TurnStatus(null, new NullLogger()));

            self::assertSame(['image_generation', null, 'image_generation', null], [$byOverride['intent'], $byOverride['model_id'], $byAgain['intent'], $byAgain['model_id']], $topic);
        }
    }

    private function classifier(?string $tag): MessageClassifier
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'CLASSIFIER' === $group && 'FAST_PATH_ENABLED' === $setting ? '0' : null
        );

        $meta = $this->createStub(MessageMetaRepository::class);
        $meta->method('findOneBy')->willReturnCallback(function (array $criteria): ?MessageMeta {
            if ('MODEL_ID' !== ($criteria['metaKey'] ?? null)) {
                return null;
            }
            $modelId = $this->createStub(MessageMeta::class);
            $modelId->method('getMetaValue')->willReturn('42');

            return $modelId;
        });

        $model = $this->createStub(Model::class);
        $model->method('getTag')->willReturn($tag ?? '');
        $models = $this->createStub(EntityRepository::class);
        $models->method('find')->willReturn($model);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($models);

        return new MessageClassifier(
            $this->createStub(MessageSorter::class),
            $meta,
            $this->modelConfig($tag),
            $config,
            $em,
            new NullLogger(),
            new TopicIntentRule(new SystemCapabilityRegistry()),
            $this->createStub(EmbeddingRouterService::class),
            new EmbeddingRouterConfig($config),
            new NativeToolRoutingConfig($config),
            new ToolCallingCapability(),
            $this->createStub(AgentPinResolver::class),
        );
    }

    private function resolver(?string $tag): TurnClassificationResolver
    {
        return new TurnClassificationResolver(
            $this->createStub(MessageClassifier::class),
            $this->modelConfig($tag),
            $this->createStub(AgentConfig::class),
            $this->createStub(PromptService::class),
            new TopicIntentRule(new SystemCapabilityRegistry()),
            new NullLogger(),
        );
    }

    private function modelConfig(?string $tag): ModelConfigService
    {
        $config = $this->createStub(ModelConfigService::class);
        $config->method('getModelTag')->willReturn($tag);

        return $config;
    }

    private function message(string $topic): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(1);
        $message->method('getUserId')->willReturn(10);
        $message->method('getText')->willReturn('redo that');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getTopic')->willReturn($topic);

        return $message;
    }
}
