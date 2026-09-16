<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;

final readonly class ApprovalRealtimeNotifier
{
    public function __construct(
        private RealtimePublisherInterface $publisher,
    ) {
    }

    public function pending(Approval $approval): void
    {
        $this->publisher->publish(
            new UserChannel($approval->getOwnerId()),
            'approval.pending',
            [
                'approvalId' => $approval->getId(),
                'tool' => $approval->getTool(),
                'preview' => $approval->getPreview(),
                'expiresAt' => $approval->getExpiresAt(),
            ],
        );
    }

    public function decided(Approval $approval): void
    {
        $this->publisher->publish(
            new UserChannel($approval->getOwnerId()),
            'approval.decided',
            [
                'approvalId' => $approval->getId(),
                'status' => $approval->getStatus(),
            ],
        );
    }

    /**
     * Published once the approved call ran (status `executed`) or could not
     * run (status `failed`). `summary` is a clipped, human-readable outcome.
     */
    public function executed(Approval $approval, ?string $summary): void
    {
        $this->publisher->publish(
            new UserChannel($approval->getOwnerId()),
            'approval.executed',
            [
                'approvalId' => $approval->getId(),
                'status' => $approval->getStatus(),
                'resultRef' => $approval->getResultRef(),
                'summary' => $summary,
            ],
        );
    }
}
