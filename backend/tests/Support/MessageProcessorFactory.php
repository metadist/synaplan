<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Plug\WebSearch\WebSearchGateway;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Mcp\ConfluenceLinkedPageReader;
use App\Service\Mcp\JiraLinkedIssueReader;
use App\Service\Message\AttachmentSearchContextResolver;
use App\Service\Message\ConversationSummaryService;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\MessagePreProcessor;
use App\Service\Message\MessageProcessor;
use App\Service\Message\Processing\LinkedContentReader;
use App\Service\Message\Processing\TurnClassificationResolver;
use App\Service\Message\Processing\TurnDispatcher;
use App\Service\Message\Processing\TurnHistoryLoader;
use App\Service\Message\Processing\TurnWebSearch;
use App\Service\Message\SearchQueryGenerator;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use App\Service\Prompt\PromptService;
use App\Service\Research\UrlContentService;
use App\Service\Research\WebResearchService;
use Psr\Log\LoggerInterface;

/**
 * Builds a MessageProcessor from its leaf dependencies, so the processor's
 * own constructor can follow its turn stages without every test changing.
 */
final class MessageProcessorFactory
{
    public static function create(
        MessageRepository $messageRepository,
        ?SearchResultRepository $searchResultRepository,
        MessagePreProcessor $preProcessor,
        MessageClassifier $classifier,
        InferenceRouter $router,
        ModelConfigService $modelConfigService,
        PromptService $promptService,
        WebSearchGateway $webSearch,
        SearchQueryGenerator $searchQueryGenerator,
        AttachmentSearchContextResolver $attachmentContextResolver,
        UrlContentService $urlContentService,
        LoggerInterface $logger,
        MultitaskRoutingConfig $multitaskConfig,
        TaskPlanner $taskPlanner,
        TaskPlanStore $taskPlanStore,
        TaskPlanExecutor $taskPlanExecutor,
        ConversationSummaryService $conversationSummaryService,
        AgentConfig $agentConfig,
        ?WebResearchService $webResearch = null,
        ?ConfluenceLinkedPageReader $confluencePages = null,
        ?JiraLinkedIssueReader $jiraIssues = null,
    ): MessageProcessor {
        return new MessageProcessor(
            $preProcessor,
            new TurnClassificationResolver($classifier, $modelConfigService, $agentConfig, $promptService, $logger),
            new TurnHistoryLoader($messageRepository, $conversationSummaryService, $logger),
            new TurnWebSearch(
                $webSearch,
                $searchQueryGenerator,
                $attachmentContextResolver,
                new LinkedContentReader($urlContentService, $logger, $webResearch, $confluencePages, $jiraIssues),
                $logger,
                $searchResultRepository,
                $webResearch,
            ),
            new TurnDispatcher($router, $taskPlanExecutor, $taskPlanner, $taskPlanStore, $multitaskConfig, $modelConfigService, $logger),
            $logger,
        );
    }
}
