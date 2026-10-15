<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolCallParser;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ModelContextWindow;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Document\ChatDocumentOutput;
use App\Service\Document\DocumentEditCoordinator;
use App\Service\Feedback\FeedbackConfigService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\GeneratedFileWriter;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\UnreadSourceGuard;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\Memory\ChatMemoryContextLoader;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Memory\UserMemoryService;
use App\Service\Message\Chat\ChatMessageAssembler;
use App\Service\Message\Chat\ChatModelSelector;
use App\Service\Message\Chat\ChatReplyGenerator;
use App\Service\Message\Chat\ChatRequestOptions;
use App\Service\Message\Chat\ChatSystemPromptAssembler;
use App\Service\Message\Chat\ChatTurnContextLoader;
use App\Service\Message\Chat\ConversationHistoryRenderer;
use App\Service\Message\Chat\SlashCommandReplies;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\PerfPipelineFlag;
use App\Service\Message\Routing\RoutingHandoffResolver;
use App\Service\Message\Routing\RoutingToolset;
use App\Service\Message\SlashCommandCopy;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginContextProviderInterface;
use App\Service\Prompt\ChatSystemPromptBuilder;
use App\Service\Prompt\PromptService;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\RAG\ChatKnowledgeContextLoader;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\SelfAware\Docs\PlatformDocsRetriever;
use App\Service\SelfAware\SelfAwarePromptDecorator;
use App\Service\Vision\VisionAttachmentEncoder;
use App\Service\Vision\VisionModelResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a ChatHandler from its leaf services, the way the container wires
 * the collaborator classes.
 *
 * The parameters are the leaf services the handler used to take directly,
 * so the behaviour tests of a chat turn keep their mocks: they hand in
 * repositories, the AI facade and config doubles, and get the real turn
 * stages — context, model choice, prompt, messages, options, reply — and
 * their collaborators around them.
 */
final class ChatHandlerFactory
{
    /**
     * @param iterable<PluginContextProviderInterface> $pluginContextProviders
     */
    public static function create(
        AiFacade $aiFacade,
        PromptRepository $promptRepository,
        PromptService $promptService,
        ModelConfigService $modelConfigService,
        ModelRepository $modelRepository,
        LoggerInterface $logger,
        VectorSearchService $vectorSearchService,
        EntityManagerInterface $em,
        string $uploadDir,
        UserUploadPathBuilder $userUploadPathBuilder,
        UserMemoryService $memoryService,
        FeedbackConfigService $feedbackConfig,
        RateLimitService $rateLimitService,
        MemoryExtractionDispatcher $memoryExtractionDispatcher,
        PerfPipelineFlag $perfPipelineFlag,
        DocumentGeneratorService $documentGenerator,
        DocumentImageReferenceResolver $documentImageReferenceResolver,
        DocumentImageCatalog $documentImageCatalog,
        TimeContextBuilder $timeContextBuilder,
        KnowledgeContextFormatter $knowledgeContextFormatter,
        VisionModelResolver $visionModelResolver,
        DigestSearchService $digestSearchService,
        MessageDigestConfig $digestConfig,
        ConversationFileCatalog $conversationFileCatalog,
        GeneratedImageVisionFlag $generatedImageVisionFlag,
        StructuredOutputConfig $structuredOutputConfig,
        ToolCallingTranslator $toolCallingTranslator,
        ToolCallParser $toolCallParser,
        RoutingToolset $routingToolset,
        ?DocumentThumbnailDispatcher $documentThumbnailDispatcher = null,
        ?GeneratedDocumentStore $generatedDocumentStore = null,
        ?DocumentEditCoordinator $documentEditCoordinator = null,
        iterable $pluginContextProviders = [],
        ?SelfAwarePromptDecorator $selfAwarePromptDecorator = null,
        ?PlatformDocsRetriever $platformDocsRetriever = null,
        ?ContextCondenser $contextCondenser = null,
        ?ModelContextWindow $modelContextWindow = null,
        ?UnreadSourceGuard $unreadSourceGuard = null,
        ?SlashCommandCopy $slashCommandCopy = null,
    ): ChatHandler {
        $history = new ConversationHistoryRenderer($conversationFileCatalog);
        $vision = new VisionAttachmentEncoder($uploadDir, $conversationFileCatalog, $generatedImageVisionFlag, $modelRepository, $visionModelResolver, $logger);
        $fileWriter = new GeneratedFileWriter($documentGenerator, $documentImageReferenceResolver, $userUploadPathBuilder, $uploadDir, $em, $logger, $documentThumbnailDispatcher);
        $messages = new ChatMessageAssembler($vision, $history, $logger, $contextCondenser, $modelContextWindow);
        $documents = new ChatDocumentOutput($modelRepository, $em, $fileWriter, $logger, $generatedDocumentStore, $documentEditCoordinator, $unreadSourceGuard);
        $sections = new ChatSystemPromptBuilder($timeContextBuilder, $documentImageCatalog, $conversationFileCatalog, $logger, $pluginContextProviders, $selfAwarePromptDecorator, $platformDocsRetriever);

        return new ChatHandler(
            new ChatTurnContextLoader(
                $promptService,
                new ChatKnowledgeContextLoader($vectorSearchService, $knowledgeContextFormatter, $logger),
                new ChatMemoryContextLoader($memoryService, $modelConfigService, $feedbackConfig, $knowledgeContextFormatter, $digestSearchService, $digestConfig, $history, $logger),
                new SlashCommandReplies($slashCommandCopy),
                $em,
                $logger,
            ),
            new ChatModelSelector($modelConfigService, $modelRepository, $vision, $logger),
            new ChatSystemPromptAssembler($sections, $documents, $messages, $logger),
            $messages,
            new ChatRequestOptions($structuredOutputConfig, $rateLimitService),
            new ChatReplyGenerator($aiFacade, $documents, $logger),
            $documents,
            new RoutingHandoffResolver($toolCallingTranslator, $toolCallParser, $routingToolset, $logger),
            new PendingMemoryExtraction($memoryExtractionDispatcher, $perfPipelineFlag, $logger),
        );
    }
}
