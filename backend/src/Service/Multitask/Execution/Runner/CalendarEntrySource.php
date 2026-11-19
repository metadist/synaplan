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
 * the conversation. A text that an earlier calendar step of the same turn
 * already entered into the same calendar is not entered twice.
 */
final readonly class CalendarEntrySource
{
    /** Result metadata key: which text a calendar step entered, and where. */
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
     * The steps are taken in one fixed order, so two calendar steps that
     * depend on the same steps read the same text, however each lists them.
     */
    public function readText(TaskNode $node, NodeContext $context, CalendarTurnEvidence $evidence): string
    {
        $dependencies = $node->dependsOn;
        sort($dependencies);

        $texts = [];
        foreach ($dependencies as $dependency) {
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

    /**
     * What was read and where it goes. The same booking may go into two
     * calendars, once into each.
     *
     * @param string $channel the calendar channel the entries go to; empty for the file alone
     */
    public static function key(string $readText, string $channel = ''): string
    {
        return sha1($readText).'|'.mb_strtolower(trim($channel));
    }

    /**
     * The planner sometimes plans one calendar step per entry it expects.
     * Each would read the same text and enter everything again. A step that
     * waits for its approval counts: it has asked for these entries.
     */
    public function alreadyEntered(NodeContext $context, string $readText, string $channel = ''): bool
    {
        return $this->claimed($context, self::key($readText, $channel), false);
    }

    /**
     * For a step that resumes after its approval. A step that only waits has
     * written nothing, so it does not stand in the way of this one.
     */
    public function enteredByAFinishedStep(NodeContext $context, string $readText, string $channel = ''): bool
    {
        return $this->claimed($context, self::key($readText, $channel), true);
    }

    private function claimed(NodeContext $context, string $key, bool $finishedOnly): bool
    {
        foreach ($context->allResults() as $result) {
            if ($key !== ($result->metadata[self::META_READ] ?? null)) {
                continue;
            }
            if (!$finishedOnly || $result->isSuccessful()) {
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
