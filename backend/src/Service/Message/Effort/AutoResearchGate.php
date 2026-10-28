<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchBudgetStore;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchStoreUnavailable;
use App\Service\Research\Run\Stage\ResearchSearch;
use Psr\Log\LoggerInterface;

/**
 * Starts a research on the chat's behalf when the sorter rated a message
 * `research` and nothing stands in the way. Checked in order; the first
 * check that fails names the limit sentence the answer then begins with.
 *
 * Widget, channel, saved-task, Again, fixed-prompt and incognito turns
 * never reach the rating: those paths say nothing about research.
 */
final readonly class AutoResearchGate
{
    /** The idempotency key prefix, so a retried stream never starts a second run for one message. */
    private const KEY_PREFIX = 'msg:';

    /** Earlier turns handed to the brief, newest last. */
    private const CONTEXT_TURNS = 6;

    public function __construct(
        private ResearchConfig $config,
        private ResearchRunService $runs,
        private ResearchSearch $search,
        private UserRepository $users,
        private RateLimitService $rateLimits,
        private LoggerInterface $logger,
        private ?ResearchBudgetStore $budgets = null,
    ) {
    }

    /**
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param array<int, Message|array{role: string, content: string}> $history
     */
    public function decide(Message $message, array $classification, array $options, array $history = []): GateDecision
    {
        if (!self::ordinaryChatTurn($message, $classification, $options)) {
            return GateDecision::none();
        }
        if (!$this->config->autoEnabled()) {
            return GateDecision::none();
        }
        $verdict = EffortVerdict::fromClassification($classification);
        if (Effort::Research !== $verdict->effort || !$verdict->voted) {
            return GateDecision::none();
        }
        $userId = $message->getUserId();
        $web = $this->search->available($userId);
        $folder = is_string($classification['rag_group_key'] ?? null) && '' !== $classification['rag_group_key'] ? $classification['rag_group_key'] : null;
        if (!$web && null === $folder) {
            return GateDecision::heldBack(GateDecision::NO_SOURCES);
        }
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return GateDecision::none();
        }
        if (!$this->rateLimits->checkCostBudget($user)['allowed']) {
            return GateDecision::heldBack(GateDecision::BUDGET);
        }

        return $this->start($user, $message, $classification, $options, $history, $web, $folder, $verdict);
    }

    /**
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     * @param array<int, Message|array{role: string, content: string}> $history
     */
    private function start(User $user, Message $message, array $classification, array $options, array $history, bool $web, ?string $folder, EffortVerdict $verdict): GateDecision
    {
        $body = [
            'question' => $message->getText(),
            'depth' => ResearchDepth::Auto->value,
            'sources' => ['web' => $web, 'knowledge' => null === $folder ? [] : [$folder]],
            'language' => is_string($classification['language'] ?? null) ? $classification['language'] : null,
            'chat_id' => $message->getChatId(),
            'assistant_id' => isset($options['agentId']) && is_numeric($options['agentId']) ? (int) $options['agentId'] : null,
            'context' => ['turns' => self::turns($history)],
            'hint' => EffortReason::AskedShort === $verdict->reason ? 'brief' : null,
        ];
        try {
            $state = $this->runs->start($user, $body, true, self::KEY_PREFIX.$message->getId());
        } catch (ResearchRejected $e) {
            $this->logger->info('Automatic research held back', ['code' => $e->errorCode, 'message_id' => $message->getId()]);

            return GateDecision::heldBack('too_many' === $e->errorCode ? GateDecision::TOO_MANY : GateDecision::UNAVAILABLE);
        } catch (ResearchStoreUnavailable) {
            return GateDecision::heldBack(GateDecision::UNAVAILABLE);
        }
        $minutes = ($this->budgets?->forDepth(ResearchDepth::Auto) ?? ResearchBudgetConfig::defaults(ResearchDepth::Auto))->minutes();

        return GateDecision::started($state->ref->id, $minutes, $verdict->reason->value);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    private static function ordinaryChatTurn(Message $message, array $classification, array $options): bool
    {
        if (null === $message->getId() || null === $message->getChatId() || 'WEB' !== $message->getMessageType()) {
            return false;
        }
        if (!empty($options['incognito']) || !empty($options['is_widget_mode']) || !empty($options['is_again'])) {
            return false;
        }
        if (!empty($options['fixed_task_prompt']) || !empty($options['saved_task_id']) || !empty($classification['saved_task_id'])) {
            return false;
        }

        return empty($classification['is_widget_mode']);
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $history
     *
     * @return list<array{role: string, text: string}>
     */
    private static function turns(array $history): array
    {
        $turns = [];
        foreach (array_slice(array_values($history), -self::CONTEXT_TURNS) as $turn) {
            if ($turn instanceof Message) {
                $turns[] = ['role' => 'IN' === $turn->getDirection() ? 'user' : 'assistant', 'text' => mb_substr($turn->getText(), 0, 2000)];
                continue;
            }
            $turns[] = ['role' => $turn['role'], 'text' => mb_substr($turn['content'], 0, 2000)];
        }

        return $turns;
    }
}
