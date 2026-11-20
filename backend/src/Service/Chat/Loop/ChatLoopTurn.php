<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallParser;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Message\Chat\ChatModelChoice;
use App\Service\Message\Chat\ChatReply;
use App\Service\Message\Chat\ChatTurn;
use App\Service\Project\ProjectChatScope;
use App\Service\Prompt\ChatPromptBlocks;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A streamed web chat turn on the loop.
 *
 * The first model call stays in the request, streaming, with the tools
 * declared. An answer without a tool call is the turn's answer, as today,
 * and no run exists. A tool call hands over: the answer's message is written
 * first, then the run opens with the call's answer, and the stream follows
 * the run until it ends, waits for the person, or has followed it long
 * enough, showing the run's text as it is written. The run posts its answer into that message itself, so a closed
 * tab loses nothing.
 */
final readonly class ChatLoopTurn
{
    /** Metadata of a handed-over turn: the message the run posts its answer into. */
    public const REPLY_ID = 'chat_run_reply_id';

    public function __construct(
        private ChatLoopGate $gate,
        private ToolCallingCapability $capability,
        private ToolCallParser $parser,
        private UserRepository $users,
        private ProjectChatScope $projects,
        private ChatRunToolbox $tools,
        private ChatRunStarter $starter,
        private ChatRunTail $tail,
        private ChatRunDelivery $delivery,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The first call of a loop turn, or null when the turn keeps today's path.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $aiOptions
     * @param array<string, mixed>       $options
     */
    public function prepare(ChatTurn $turn, ChatModelChoice $model, array $messages, array $aiOptions, array $options): ?ChatLoopCall
    {
        $system = $messages[0] ?? null;
        if (!$this->gate->forTurn($turn->message, $options, $turn->classification)
            || null === $model->provider || !$this->capability->supports($model->provider, $model->modelName, true)
            || 'system' !== ($system['role'] ?? null) || !\is_string($system['content'] ?? null)) {
            return null;
        }
        $scope = $this->scope($turn->message, $turn->personal->user);
        if (null === $scope) {
            return null;
        }
        $basePrompt = $this->basePrompt($system['content'], $turn, $options);
        if (!$this->gate->affords($scope->user, $model->model, $messages)) {
            // No run that could only stop at once: an answer without tools that says why.
            $messages[0]['content'] = rtrim($system['content'])."\n\n".ChatLoopPrompt::LOW_BUDGET;

            return new ChatLoopCall($messages, $aiOptions, $basePrompt, \array_slice($messages, 1), $scope);
        }

        $messages[0]['content'] = rtrim($system['content'])."\n\n".ChatLoopPrompt::rules();
        $aiOptions['tools'] = [...$this->tools->declarations($scope), ChatLoopTools::updatePlan()];
        $aiOptions['tool_choice'] = 'auto';
        // The run sends the whole transcript; a stored provider response would skip it.
        unset($aiOptions['previous_response_id']);

        return new ChatLoopCall($messages, $aiOptions, $basePrompt, \array_slice($messages, 1), $scope);
    }

    /**
     * Hands a first answer with tool calls to a new run and follows it.
     *
     * @return array<string, mixed> the metadata that marks the turn as handed over
     */
    public function handOver(ChatLoopCall $call, ChatTurn $turn, ChatModelChoice $model, ChatReply $reply, callable $streamCallback, ?callable $progressCallback): array
    {
        $message = $turn->message;
        $preamble = \is_string($reply->content) ? $reply->content : '';
        $row = $this->replyRow($turn, $call->scope->chat, $preamble);
        try {
            $start = $this->starter->start(new ChatRunRequest(
                (int) $call->scope->user->getId(),
                (int) $call->scope->user->getId(),
                (int) $call->scope->chat->getId(),
                $message->getId(),
                new ChatRunPrompt($model->provider, $model->modelName, $turn->language(), $call->basePrompt, $call->transcript, $this->gate->rates($model->model)),
                mb_substr($message->getText(), 0, 500),
                'chat-turn-'.$message->getId(),
                new ChatRunReply($preamble, ChatRunCalls::read($this->parser, $reply->toolCalls), FacadeChatRunModel::micro($reply->metadata['facade_charged_cost'] ?? null)),
                $row->getId(),
            ));
        } catch (\Throwable $e) {
            $this->em->remove($row);
            $this->em->flush();

            throw $e;
        }

        // The tail replays the run's log from the start, its `started` event included.
        $state = $this->tail->follow($start->runId, (string) ($turn->options['track_id'] ?? ''), $streamCallback, $progressCallback);
        if (null !== $state && $state->isTerminal()) {
            // Whoever comes first posts it; the stream must not wait for the worker.
            $this->delivery->deliver($state);
            $rest = ChatRunDelivery::rest($state);
            if ('' !== $rest) {
                $streamCallback($rest);
            }
        }

        return [self::REPLY_ID => $row->getId(), ChatRunDelivery::META => $start->runId];
    }

    private function scope(Message $message, ?User $user): ?ChatRunScope
    {
        $user ??= $this->users->find($message->getUserId());
        $chat = $message->getChat();
        if (!$user instanceof User || !$user->isActive() || !$chat instanceof Chat || $chat->getUserId() !== $user->getId()) {
            return null;
        }

        return new ChatRunScope($user, $chat, $this->projects->forChat($chat));
    }

    /**
     * The chat's system prompt as every unit starts from it: the project's
     * instructions and the sources found in its folder are left out, because
     * a unit adds them only while the chat is still in the project.
     *
     * @param array<string, mixed> $options
     */
    private function basePrompt(string $system, ChatTurn $turn, array $options): string
    {
        if (!\is_string($options['rag_group_key'] ?? null)) {
            return $system;
        }
        $instructions = $options['project_instructions'] ?? null;
        if (\is_string($instructions) && '' !== trim($instructions)) {
            $system = str_replace(ChatPromptBlocks::projectInstructions(\is_string($options['project_name'] ?? null) ? $options['project_name'] : '', $instructions), '', $system);
        }
        if ('' !== $turn->knowledge->context) {
            $system = str_replace($turn->knowledge->context, '', $system);
        }

        return $system;
    }

    /** The answer's message, written before the run exists so the run and the stream share one row. */
    private function replyRow(ChatTurn $turn, Chat $chat, string $preamble): Message
    {
        $message = $turn->message;
        $now = time();
        $row = (new Message())
            ->setUserId((int) $message->getUserId())
            ->setChat($chat)
            ->setTrackingId($message->getTrackingId())
            ->setProviderIndex($message->getProviderIndex())
            ->setUnixTimestamp($now)
            ->setDateTime(date('YmdHis', $now))
            ->setMessageType('WEB')
            ->setFile(0)
            ->setTopic($turn->topic())
            ->setLanguage($turn->language())
            ->setDirection('OUT')
            ->setText($preamble)
            ->setStatus('processing');
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }
}
