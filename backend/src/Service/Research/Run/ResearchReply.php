<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\PlainText;

/**
 * One reply while a run is waiting. Answering the last open question, or
 * approving the plan, returns the step to queue. A reply that leaves a
 * question open returns null, and the run keeps waiting with no worker.
 */
final class ResearchReply
{
    private const TEXT = 300;

    /**
     * @param array<string, mixed> $body
     *
     * @return string|null the step to queue, or null while a question is still open
     */
    public static function apply(ResearchRunState $state, array $body): ?string
    {
        $type = is_string($body['type'] ?? null) ? $body['type'] : '';

        return match ($state->status) {
            ResearchStatus::WaitingAnswer => self::answer($state, $type, $body),
            ResearchStatus::WaitingPlan => self::plan($state, $type, $body),
            default => throw new ResearchRejected(409, 'not_waiting', null, 'This research is not waiting for a reply.'),
        };
    }

    /** Every open question counts as skipped, and the run continues. */
    public static function skipAll(ResearchRunState $state): string
    {
        $wait = $state->questions;
        if (null === $wait) {
            $state->advance(ResearchStatus::Planning);

            return ResearchUnitPlan::BRIEF_PLAN;
        }
        foreach ($wait->items as $item) {
            if ($item->open()) {
                $item->skipped = true;
                self::remember($state, $item->text, 'Skipped');
            }
        }

        return self::finish($state, $wait);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function answer(ResearchRunState $state, string $type, array $body): ?string
    {
        if (!in_array($type, ['answer', 'skip_question'], true)) {
            throw new ResearchRejected(400, 'invalid', 'type', 'That reply is not available for this research.');
        }
        $wait = $state->questions;
        $question = self::openQuestion($wait, $body);
        if ('skip_question' === $type) {
            $question->skipped = true;
            self::remember($state, $question->text, 'Skipped');
        } else {
            $question->answer = self::choice($question, $body);
            self::remember($state, $question->text, $question->answer);
        }
        if (null === $wait || $wait->anyOpen()) {
            return null;
        }

        return self::finish($state, $wait);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function plan(ResearchRunState $state, string $type, array $body): string
    {
        if ('edit_plan' === $type) {
            ResearchPlanEdit::apply($state->ledger, $body);
        } elseif ('approve_plan' !== $type) {
            throw new ResearchRejected(400, 'invalid', 'type', 'That reply is not available for this research.');
        }
        $state->advance(ResearchStatus::Gathering);

        return ResearchUnitPlan::ROUND;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function openQuestion(?ResearchQuestions $wait, array $body): ResearchQuestion
    {
        if (null === $wait) {
            throw new ResearchRejected(409, 'not_waiting', 'question', 'This research is not waiting for a reply.');
        }
        $id = is_string($body['question'] ?? null) ? $body['question'] : '';
        if ('' === $id) {
            $open = array_values(array_filter($wait->items, static fn (ResearchQuestion $item): bool => $item->open()));
            if (1 !== count($open)) {
                throw new ResearchRejected(400, 'invalid', 'question', 'Say which question this reply is for.');
            }

            return $open[0];
        }
        $question = $wait->find($id);
        if (null === $question || !$question->open()) {
            throw new ResearchRejected(400, 'invalid', 'question', 'That question is not waiting for a reply.');
        }

        return $question;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function choice(ResearchQuestion $question, array $body): string
    {
        $choice = is_string($body['choice'] ?? null) ? PlainText::line($body['choice'], self::TEXT) : '';
        if ('' !== $choice && in_array($choice, $question->options, true)) {
            return $choice;
        }
        $text = is_string($body['text'] ?? null) ? PlainText::line($body['text'], self::TEXT) : '';
        if ($question->allowText && '' !== $text) {
            return $text;
        }

        throw new ResearchRejected(400, 'invalid', 'choice', $question->allowText ? 'Choose one of the options, or write your own answer.' : 'Choose one of the options.');
    }

    private static function finish(ResearchRunState $state, ResearchQuestions $wait): string
    {
        if (null !== $wait->toolCallId) {
            $item = $wait->items[0] ?? null;
            $content = null !== $item && $item->skipped
                ? '{"skipped":true}'
                : json_encode(['answer' => $item->answer ?? ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $state->conversation->replaceResult($wait->toolCallId, $content);
        }
        $status = ResearchStatus::tryFrom($wait->resumeStatus) ?? ResearchStatus::Planning;
        $state->questions = null;
        $state->advance($status);

        return $wait->resumeUnit;
    }

    private static function remember(ResearchRunState $state, string $question, string $answer): void
    {
        $state->replies[] = ['question' => $question, 'answer' => $answer];
    }
}
