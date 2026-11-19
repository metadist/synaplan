<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\Calendar\CalendarEntryExtraction;
use App\Service\Calendar\CalendarEntryExtractor;
use App\Service\Calendar\CalendarTurnEvidence;
use App\Service\Exception\StreamCancelledException;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Usage\AiChatUsageMeter;
use Psr\Log\LoggerInterface;

/**
 * Where a calendar step takes its entries from when its plan carries no
 * start time the person named: "put the three flights of this booking into
 * my calendar" names no hour, the picture does.
 *
 * The step reads what the steps it depends on returned (a file analysis, a
 * mail search, a fetched page). Without such a step it reads the files of
 * the turn: those attached to this message, else those attached earlier in
 * the conversation. A text that was already entered by an earlier calendar
 * step of the same turn is not entered twice.
 */
final readonly class CalendarEntrySource
{
    /** Result metadata key: which text a calendar step entered from. */
    public const META_READ = 'calendar_read';

    private const MAX_READ_CHARS = 24000;

    public function __construct(
        private CalendarEntryExtractor $extractor,
        private LoggerInterface $logger,
        private ?ChatErrorPresenter $errorPresenter = null,
    ) {
    }

    /**
     * The text this step may take entries from; empty when nothing was read.
     */
    public function readText(TaskNode $node, NodeContext $context, CalendarTurnEvidence $evidence): string
    {
        $texts = [];
        foreach ($node->dependsOn as $dependency) {
            $result = $context->getResult($dependency);
            $text = null !== $result && $result->isSuccessful() ? trim((string) $result->text) : '';
            if ('' !== $text) {
                $texts[] = $text;
            }
        }
        if ([] === $texts) {
            return $evidence->fileText;
        }

        return mb_substr(implode("\n\n---\n\n", $texts), 0, self::MAX_READ_CHARS);
    }

    public static function key(string $readText): string
    {
        return sha1($readText);
    }

    /**
     * The planner sometimes plans one calendar step per entry it expects.
     * Each would read the same text and enter everything again.
     */
    public function alreadyEntered(NodeContext $context, string $readText): bool
    {
        $key = self::key($readText);
        foreach ($context->allResults() as $result) {
            if ($key === ($result->metadata[self::META_READ] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries the read text holds, or the failed step when the model
     * could not be asked. A stop by the person is not a failure.
     */
    public function read(NodeContext $context, string $readText, \DateTimeZone $zone, string $language): CalendarEntryExtraction|NodeResult
    {
        try {
            return $this->extractor->extract(
                (string) $context->message->getText(),
                $readText,
                $zone,
                $context->userId,
                array_filter([
                    AiChatUsageMeter::USAGE_SOURCE => 'MULTITASK',
                    AiChatUsageMeter::USAGE_CHAT_ID => $context->message->getChatId(),
                    AiChatUsageMeter::USAGE_AGENT_ID => $context->options['agentId'] ?? null,
                ], static fn (mixed $value): bool => null !== $value),
            );
        } catch (StreamCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('CalendarEntrySource: reading calendar entries failed', [
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed(
                $this->errorPresenter instanceof ChatErrorPresenter
                    ? $this->errorPresenter->present($e, $language)->userText
                    : 'Something went wrong while answering this request. Please try again.',
            );
        }
    }
}
