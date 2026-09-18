<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\Message;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Continues a chat turn after one of its approvals is decided (Q1).
 *
 * Approving a chat approval runs the tool in a worker, but the thread stayed
 * silent: no follow-up message, nothing for an open chat to refresh on. This
 * service appends exactly one plain-language assistant message to the thread
 * and publishes {@see EVENT_CHAT_CONTINUED} so the open chat reloads it.
 * Approving from the chat card and from the Approvals inbox behave the same
 * (J-TL-1, J-TL-2) because both paths end here.
 *
 * The message is persisted WITHOUT flushing: the caller saves the approval
 * right after, and that flush persists both rows atomically. A worker retry
 * before the flush re-runs cleanly (nothing was written); after it the
 * approval is no longer APPROVED/PENDING so the caller returns early.
 * The realtime event is returned as a closure the caller runs AFTER the
 * approval save (publish-after-commit: subscribers reload messages that
 * must already be committed).
 */
final readonly class ChatApprovalContinuationService
{
    public const EVENT_CHAT_CONTINUED = 'approval.chat_continued';

    public const OUTCOME_EXECUTED = 'executed';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_UNSUPPORTED = 'unsupported';

    public function __construct(
        private MessageRepository $messages,
        private ChatRepository $chats,
        private UserRepository $users,
        private TranslatorInterface $translator,
        private RealtimePublisherInterface $publisher,
    ) {
    }

    /**
     * @param self::OUTCOME_* $outcome
     *
     * @return (callable(): void)|null announcer to run after the approval save, or null when the approval is not a continuable chat approval
     */
    public function continueChat(Approval $approval, string $outcome, ?string $detail): ?callable
    {
        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_CHAT !== $reference->kind || null === $reference->messageId || $reference->messageId <= 0) {
            return null;
        }
        $trigger = $this->messages->find($reference->messageId);
        if (!$trigger instanceof Message || $trigger->getUserId() !== $approval->getOwnerId()) {
            return null;
        }
        $chatId = $trigger->getChatId();
        if (null === $chatId || $chatId <= 0) {
            return null;
        }
        $chat = $this->chats->find($chatId);
        if (null === $chat) {
            return null;
        }
        $owner = $this->users->find($approval->getOwnerId());
        $locale = null !== $owner ? $owner->getLocale() : 'en';

        $followUp = new Message();
        $followUp->setUserId($approval->getOwnerId());
        $followUp->setChat($chat);
        $followUp->setText($this->text($outcome, $approval, $detail, $locale));
        $followUp->setDirection('OUT');
        $followUp->setStatus('complete');
        $followUp->setMessageType($trigger->getMessageType());
        $followUp->setTopic($trigger->getTopic());
        $followUp->setLanguage($trigger->getLanguage());
        $followUp->setTrackingId(time());
        $followUp->setUnixTimestamp(time());
        $followUp->setDateTime(date('YmdHis'));
        $followUp->setProviderIndex('SYNA_APPROVAL');
        $followUp->setFile(0);
        $this->messages->save($followUp, false);

        $ownerId = $approval->getOwnerId();
        $approvalId = $approval->getId();

        return function () use ($ownerId, $approvalId, $chatId, $outcome): void {
            $this->publisher->publish(
                new UserChannel($ownerId),
                self::EVENT_CHAT_CONTINUED,
                [
                    'approvalId' => $approvalId,
                    'chatId' => $chatId,
                    'outcome' => $outcome,
                ],
            );
        };
    }

    /**
     * @param self::OUTCOME_* $outcome
     */
    private function text(string $outcome, Approval $approval, ?string $detail, string $locale): string
    {
        $preview = trim((string) $approval->getPreview());
        if ('' === $preview) {
            $preview = $approval->getTool();
        }
        $detail = null !== $detail ? trim($detail) : '';

        return match ($outcome) {
            self::OUTCOME_EXECUTED => '' !== $detail
                ? $this->translator->trans('approval.chat_followup.executed_with_result', ['%preview%' => $preview, '%result%' => $detail], 'approvals', $locale)
                : $this->translator->trans('approval.chat_followup.executed', ['%preview%' => $preview], 'approvals', $locale),
            self::OUTCOME_FAILED => '' !== $detail
                ? $this->translator->trans('approval.chat_followup.failed', ['%preview%' => $preview, '%reason%' => $detail], 'approvals', $locale)
                : $this->translator->trans('approval.chat_followup.failed_generic', ['%preview%' => $preview], 'approvals', $locale),
            self::OUTCOME_REJECTED => $this->translator->trans('approval.chat_followup.rejected', [], 'approvals', $locale),
            default => $this->translator->trans('approval.chat_followup.unsupported', ['%preview%' => $preview], 'approvals', $locale),
        };
    }
}
