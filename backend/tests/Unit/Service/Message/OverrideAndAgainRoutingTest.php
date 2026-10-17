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
 * already on the message, with the topic and intent each entry answers.
 */
final class OverrideAndAgainRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: array{0: string, 1: string}, 3: array{0: string, 1: string}}>
     */
    public static function rows(): iterable
    {
        $rows = [
            // model tag, topic on the message, model override [topic, intent], Again [topic, intent]
            ['text2pic', '', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['text2pic', 'mediamaker', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['text2vid', '', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['text2vid', 'mediamaker', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['text2sound', '', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['text2sound', 'mediamaker', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['pic2text', '', ['general', 'chat'], ['general', 'chat']],
            ['pic2text', 'mediamaker', ['general', 'chat'], ['general', 'chat']],
            ['analyze', '', ['general', 'chat'], ['general', 'chat']],
            ['analyze', 'mediamaker', ['general', 'chat'], ['general', 'chat']],
            ['vision', '', ['general', 'chat'], ['general', 'chat']],
            ['vision', 'mediamaker', ['mediamaker', 'image_generation'], ['general', 'chat']],
            ['document', '', ['general', 'chat'], ['officemaker', 'document_generation']],
            ['document', 'mediamaker', ['mediamaker', 'image_generation'], ['officemaker', 'document_generation']],
            ['officemaker', '', ['general', 'chat'], ['officemaker', 'document_generation']],
            ['officemaker', 'mediamaker', ['mediamaker', 'image_generation'], ['officemaker', 'document_generation']],
            ['text2doc', '', ['general', 'chat'], ['officemaker', 'document_generation']],
            ['text2doc', 'mediamaker', ['mediamaker', 'image_generation'], ['officemaker', 'document_generation']],
            ['chat', '', ['general', 'chat'], ['chat', 'chat']],
            ['chat', 'UNKNOWN', ['general', 'chat'], ['chat', 'chat']],
            ['chat', 'legal-review', ['general', 'chat'], ['legal-review', 'chat']],
            ['chat', 'mediamaker', ['general', 'chat'], ['mediamaker', 'image_generation']],
            ['chat', 'tools:pic', ['general', 'chat'], ['tools:pic', 'chat']],
            ['vectorize', '', ['general', 'chat'], ['chat', 'chat']],
            ['vectorize', 'UNKNOWN', ['general', 'chat'], ['chat', 'chat']],
            ['vectorize', 'legal-review', ['general', 'chat'], ['legal-review', 'chat']],
            ['vectorize', 'mediamaker', ['general', 'chat'], ['mediamaker', 'image_generation']],
            ['vectorize', 'tools:pic', ['general', 'chat'], ['tools:pic', 'chat']],
            ['rerank', '', ['general', 'chat'], ['chat', 'chat']],
            ['rerank', 'unknown', ['unknown', 'chat'], ['chat', 'chat']],
            ['rerank', 'UNKNOWN', ['UNKNOWN', 'chat'], ['chat', 'chat']],
            ['rerank', 'legal-review', ['legal-review', 'chat'], ['legal-review', 'chat']],
            ['rerank', 'mediamaker', ['mediamaker', 'image_generation'], ['mediamaker', 'image_generation']],
            ['rerank', 'tools:pic', ['tools:pic', 'image_generation'], ['tools:pic', 'chat']],
            [null, '', ['general', 'chat'], ['chat', 'chat']],
            [null, 'legal-review', ['general', 'chat'], ['legal-review', 'chat']],
        ];

        foreach ($rows as $row) {
            yield sprintf('%s on "%s"', $row[0] ?? 'no tag', $row[1]) => $row;
        }
    }

    /**
     * @param array{0: string, 1: string} $override
     * @param array{0: string, 1: string} $again
     */
    #[DataProvider('rows')]
    public function testAModelOverrideAndAnAgainRouteTheTagAndTopicAsPinned(?string $tag, string $topic, array $override, array $again): void
    {
        $byOverride = $this->classifier($tag)->classify($this->message($topic));
        self::assertSame($override, [$byOverride['topic'], $byOverride['intent']], 'model override');

        $byAgain = $this->resolver($tag)->againForBufferedTurn($this->message($topic), ['is_again' => true, 'model_id' => 42], new TurnStatus(null, new NullLogger()));
        self::assertSame($again, [$byAgain['topic'], $byAgain['intent']], 'Again');
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
