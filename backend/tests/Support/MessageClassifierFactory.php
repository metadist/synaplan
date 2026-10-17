<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\ToolCalling\ToolCallingCapability;
use App\Repository\ConfigRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPinResolver;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\AgentService;
use App\Service\Context\AttachmentDigest;
use App\Service\Document\DocumentToolsConfig;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Classification\AgainOverride;
use App\Service\Message\Classification\AgentPinRoute;
use App\Service\Message\Classification\AttachmentIntent;
use App\Service\Message\Classification\FastPathClassifier;
use App\Service\Message\Classification\SelfAwareQuestion;
use App\Service\Message\Classification\SlashCommandRoute;
use App\Service\Message\Classification\SorterInput;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\SelfAware\SelfAwareConfig;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The message classifier, built from the doubles a test names. The optional
 * collaborators are absent unless a test hands them in.
 */
final class MessageClassifierFactory
{
    /**
     * @param array{
     *     messageSorter: MessageSorter,
     *     messageMetaRepository: MessageMetaRepository,
     *     modelConfigService: ModelConfigService,
     *     configRepository: ConfigRepository,
     *     em: EntityManagerInterface,
     *     logger: LoggerInterface,
     *     topicIntents: TopicIntentRule,
     *     embeddingRouter: EmbeddingRouterService,
     *     embeddingRouterConfig: EmbeddingRouterConfig,
     *     nativeToolRoutingConfig: NativeToolRoutingConfig,
     *     toolCallingCapability: ToolCallingCapability,
     *     agentPin: AgentPinResolver,
     *     selfAwareConfig?: ?SelfAwareConfig,
     *     officeConverter?: ?OfficeConverterClient,
     *     documentToolsConfig?: ?DocumentToolsConfig,
     *     multitaskConfig?: ?MultitaskRoutingConfig,
     *     agentConfig?: ?AgentConfig,
     *     agentService?: ?AgentService,
     *     agentRuntimeResolver?: ?AgentRuntimeResolver,
     *     users?: ?UserRepository,
     *     attachmentDigest?: ?AttachmentDigest,
     * } $doubles
     */
    public static function build(array $doubles): MessageClassifier
    {
        $logger = $doubles['logger'];
        $topicIntents = $doubles['topicIntents'];
        $sorterInput = new SorterInput($doubles['attachmentDigest'] ?? null);
        $selfAware = new SelfAwareQuestion($doubles['selfAwareConfig'] ?? null);

        return new MessageClassifier(
            $doubles['messageSorter'],
            new SlashCommandRoute($topicIntents, $logger),
            $doubles['modelConfigService'],
            new FastPathClassifier($doubles['configRepository'], $selfAware, $logger, $doubles['officeConverter'] ?? null),
            new AttachmentIntent($doubles['em'], $logger, $doubles['officeConverter'] ?? null, $doubles['documentToolsConfig'] ?? null, $doubles['multitaskConfig'] ?? null),
            $logger,
            $topicIntents,
            $doubles['embeddingRouter'],
            $doubles['embeddingRouterConfig'],
            $doubles['nativeToolRoutingConfig'],
            $doubles['toolCallingCapability'],
            new AgentPinRoute($doubles['agentPin'], $doubles['messageSorter'], $sorterInput, $logger, $doubles['agentConfig'] ?? null, $doubles['agentService'] ?? null, $doubles['agentRuntimeResolver'] ?? null, $doubles['users'] ?? null),
            new AgainOverride($doubles['messageMetaRepository'], $doubles['modelConfigService'], $topicIntents, $logger),
            $sorterInput,
            $selfAware,
        );
    }
}
