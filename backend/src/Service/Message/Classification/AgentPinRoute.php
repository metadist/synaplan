<?php

namespace App\Service\Message\Classification;

use App\Entity\Agent;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentPinResolver;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\AgentService;
use App\Service\Message\MessageSorter;
use App\Service\Message\Routing\RoutingDecision;
use App\Service\Message\Routing\RoutingLayer;
use App\Service\Message\WebSearchTopicPolicy;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * An assistant decides the topic of its turns: a pinned assistant (`agentId`,
 * flag on) answers with its own prompt and models, asking the sorter only
 * whether the turn needs the web; a published assistant the sorter picked
 * (`agent:{slug}`, routable flag on) gets its runtime profile attached.
 */
final readonly class AgentPinRoute
{
    public function __construct(
        private AgentPinResolver $agentPin,
        private MessageSorter $messageSorter,
        private SorterInput $sorterInput,
        private LoggerInterface $logger,
        private ?AgentConfig $agentConfig = null,
        private ?AgentService $agentService = null,
        private ?AgentRuntimeResolver $agentRuntimeResolver = null,
        private ?UserRepository $users = null,
    ) {
    }

    /**
     * Pin the turn to an assistant when `agentId` is present and the flag is on.
     * Runs before the fast-path and before PROMPTID so a pinned chat never
     * becomes `general`. MessageSorter never picks the topic on this path.
     *
     * The RuntimeProfile travels as `runtime_profile`; RAG scope, limit and
     * score are read from it downstream and never copied into scalar keys.
     *
     * The topic stays pinned, but an assistant that may use the internet still
     * needs the sorter's BWEBSEARCH vote: without it a pinned chat could never
     * search for live data (weather, prices, news) on its own.
     *
     * @param array<string, mixed> $options
     * @param array<int, mixed>    $conversationHistory
     *
     * @return array<string, mixed>|null
     */
    public function pin(Message $message, array $options, array $conversationHistory = [], bool $searchAlreadyForced = false): ?array
    {
        $profile = $this->agentPin->resolve($message, $options);
        if (null === $profile) {
            return null;
        }

        $decision = RoutingDecision::deterministic(RoutingLayer::AgentPin, $profile->promptTopic);
        $language = $message->getLanguage();
        if (!$language || 'NN' === $language) {
            $language = 'en';
        }

        $searchVote = $this->pinnedWebSearchVote($message, $profile, $conversationHistory, $searchAlreadyForced);

        $this->logger->info('MessageClassifier: Using agent pin (topic not sorted)', [
            'message_id' => $message->getId(),
            'agent_id' => $profile->agentId,
            'topic' => $profile->promptTopic,
            'web_search_vote' => $searchVote['web_search'],
        ]);

        return array_merge([
            'topic' => $profile->promptTopic,
            'language' => $language,
            'web_search' => $searchVote['web_search'],
            'read_pages' => $searchVote['read_pages'],
            'source' => $decision->toClassificationSource(),
            'skip_sorting' => true,
            'intent' => 'chat',
            'prompt_id' => $profile->promptId,
            'agent_id' => $profile->agentId,
            'agent_version_id' => $profile->agentVersionId,
            'model_id' => $profile->modelIds['chat'] ?? null,
            'runtime_profile' => $profile,
        ], $decision->toClassificationFields());
    }

    /**
     * When the sorter returns `agent:{slug}` and the routable flag is on,
     * attach the published assistant's RuntimeProfile. Flag off ⇒ no-op.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    public function attachRoutableAgent(array $classification, Message $message): array
    {
        if (null === $this->agentConfig || null === $this->agentService || null === $this->agentRuntimeResolver || null === $this->users) {
            return $classification;
        }

        $userId = $message->getUserId();
        if (!$this->agentConfig->isEnabled($userId) || !$this->agentConfig->isRoutableEnabled($userId)) {
            return $classification;
        }

        $topic = (string) ($classification['topic'] ?? '');
        if (!str_starts_with($topic, Agent::TOPIC_PREFIX)) {
            return $classification;
        }

        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return $classification;
        }

        $slug = substr($topic, strlen(Agent::TOPIC_PREFIX));
        foreach ($this->agentService->routableForUser($user) as $agent) {
            if ($agent->getSlug() !== $slug || null === $agent->getId()) {
                continue;
            }
            $chatId = $message->getChatId();
            try {
                $profile = $this->agentRuntimeResolver->resolve(
                    $agent->getId(),
                    $user,
                    false,
                    null !== $chatId && $chatId > 0 ? $chatId : null,
                );
            } catch (\Throwable $e) {
                $this->logger->warning('MessageClassifier: routable assistant could not be resolved, keeping sorter topic', [
                    'message_id' => $message->getId(),
                    'agent_id' => $agent->getId(),
                    'topic' => $topic,
                    'error' => $e->getMessage(),
                ]);

                return $classification;
            }
            $classification['runtime_profile'] = $profile;
            $classification['agent_id'] = $profile->agentId;
            $classification['agent_version_id'] = $profile->agentVersionId;
            $classification['prompt_id'] = $profile->promptId;
            if (isset($profile->modelIds['chat']) && $profile->modelIds['chat']) {
                $classification['model_id'] = $profile->modelIds['chat'];
            }

            return $classification;
        }

        return $classification;
    }

    /**
     * Ask the sorter only whether a pinned assistant turn needs the web.
     *
     * No vote (null) when the assistant may not use the internet, when the
     * user already forced or asked for a search, or for small talk — the
     * policy decides those without a model call.
     *
     * @param array<int, mixed> $conversationHistory
     *
     * @return array{web_search: ?bool, read_pages: ?int}
     */
    private function pinnedWebSearchVote(Message $message, RuntimeProfile $profile, array $conversationHistory, bool $searchAlreadyForced): array
    {
        $noVote = ['web_search' => null, 'read_pages' => null];
        $text = $message->getText();

        if (false === ($profile->toolFlags['tool_internet'] ?? null)
            || $searchAlreadyForced
            || WebSearchTopicPolicy::isExplicitSearchRequest($text)
            || WebSearchTopicPolicy::isTrivialConversational($text)) {
            return $noVote;
        }

        $result = $this->messageSorter->classify($this->sorterInput->for($message), $conversationHistory, $message->getUserId());
        $readPages = $result['read_pages'] ?? null;

        return [
            'web_search' => true === ($result['web_search'] ?? null),
            'read_pages' => is_int($readPages) ? $readPages : null,
        ];
    }
}
