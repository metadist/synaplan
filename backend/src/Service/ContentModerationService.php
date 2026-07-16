<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentReport;
use App\Entity\User;
use App\Repository\ContentReportRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Content moderation (Apple App Review Guideline 1.2).
 *
 * Owns the report lifecycle: a user reports objectionable content, the content
 * owner is resolved server-side, the report is persisted and the operator is
 * notified by email. Operators review reports and, when warranted, suspend the
 * offending account.
 */
final readonly class ContentModerationService
{
    /** @var list<string> */
    public const ALLOWED_CONTENT_TYPES = [
        ContentReport::CONTENT_TYPE_MESSAGE,
        ContentReport::CONTENT_TYPE_FILE,
    ];

    /** @var list<string> */
    public const ALLOWED_REASONS = [
        'spam',
        'harassment',
        'hate_speech',
        'violence',
        'sexual_content',
        'csae', // child sexual abuse & exploitation
        'illegal',
        'other',
    ];

    /** @var list<string> */
    public const ALLOWED_ACCOUNT_STATUSES = [
        User::ACCOUNT_STATUS_ACTIVE,
        User::ACCOUNT_STATUS_SUSPENDED,
        User::ACCOUNT_STATUS_BANNED,
    ];

    /** @var list<string> */
    public const ALLOWED_REPORT_STATUSES = [
        ContentReport::STATUS_OPEN,
        ContentReport::STATUS_REVIEWED,
        ContentReport::STATUS_ACTIONED,
        ContentReport::STATUS_DISMISSED,
    ];

    public function __construct(
        private ContentReportRepository $reportRepository,
        private UserRepository $userRepository,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private InternalEmailService $emailService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * File a report. Returns the created report, or the existing one if this
     * reporter already has an open report for the same content (idempotent).
     *
     * @throws \InvalidArgumentException on an unknown content type or reason
     */
    public function report(User $reporter, string $contentType, int $contentId, string $reason, ?string $details): ContentReport
    {
        if (!in_array($contentType, self::ALLOWED_CONTENT_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported content type: '.$contentType);
        }
        if (!in_array($reason, self::ALLOWED_REASONS, true)) {
            throw new \InvalidArgumentException('Unsupported reason: '.$reason);
        }

        $reporterId = (int) $reporter->getId();

        if ($this->reportRepository->existsOpenForContent($reporterId, $contentType, $contentId)) {
            $this->logger->info('Duplicate content report ignored', [
                'reporter_id' => $reporterId,
                'content_type' => $contentType,
                'content_id' => $contentId,
            ]);

            // Return a lightweight representation of the existing intent without
            // creating a second row; controller treats this as success.
            $existing = new ContentReport();
            $existing->setReporterId($reporterId)
                ->setContentType($contentType)
                ->setContentId($contentId)
                ->setReason($reason)
                ->setStatus(ContentReport::STATUS_OPEN)
                ->setCreated(date('Y-m-d H:i:s'));

            return $existing;
        }

        $reportedUserId = $this->resolveOwner($contentType, $contentId);

        $trimmedDetails = null !== $details ? trim($details) : null;
        if (null !== $trimmedDetails && mb_strlen($trimmedDetails) > 2000) {
            $trimmedDetails = mb_substr($trimmedDetails, 0, 2000);
        }

        $report = new ContentReport();
        $report->setReporterId($reporterId)
            ->setContentType($contentType)
            ->setContentId($contentId)
            ->setReportedUserId($reportedUserId)
            ->setReason($reason)
            ->setDetails('' !== (string) $trimmedDetails ? $trimmedDetails : null)
            ->setStatus(ContentReport::STATUS_OPEN)
            ->setCreated(date('Y-m-d H:i:s'));

        $this->reportRepository->save($report);

        $reportedUser = null !== $reportedUserId ? $this->userRepository->find($reportedUserId) : null;

        $this->emailService->sendModerationReportEmail([
            'id' => (int) $report->getId(),
            'contentType' => $report->getContentType(),
            'contentId' => $report->getContentId(),
            'reason' => $report->getReason(),
            'details' => $report->getDetails(),
            'reporterId' => $reporterId,
            'reporterEmail' => $reporter->getMail(),
            'reportedUserId' => $reportedUserId,
            'reportedUserEmail' => $reportedUser?->getMail(),
            'created' => $report->getCreated(),
        ]);

        $this->logger->info('Content report created', [
            'report_id' => $report->getId(),
            'reporter_id' => $reporterId,
            'reported_user_id' => $reportedUserId,
        ]);

        return $report;
    }

    /**
     * @return array{reports: ContentReport[], total: int}
     */
    public function listReports(?string $status, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'reports' => $this->reportRepository->findFiltered($status, $perPage, $offset),
            'total' => $this->reportRepository->countFiltered($status),
        ];
    }

    public function updateReportStatus(ContentReport $report, string $status, int $adminId): ContentReport
    {
        if (!in_array($status, self::ALLOWED_REPORT_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid report status: '.$status);
        }

        $report->setStatus($status)
            ->setReviewedBy($adminId)
            ->setReviewedAt(date('Y-m-d H:i:s'));

        $this->reportRepository->save($report);

        return $report;
    }

    public function setAccountStatus(User $user, string $status): User
    {
        if (!in_array($status, self::ALLOWED_ACCOUNT_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid account status: '.$status);
        }

        $user->setAccountStatus($status);
        $this->userRepository->save($user);

        $this->logger->info('User account status changed', [
            'user_id' => $user->getId(),
            'status' => $status,
        ]);

        return $user;
    }

    /**
     * Resolve the owner (user id) of the reported content, best-effort.
     */
    private function resolveOwner(string $contentType, int $contentId): ?int
    {
        if (ContentReport::CONTENT_TYPE_MESSAGE === $contentType) {
            $message = $this->messageRepository->find($contentId);

            return null !== $message ? $message->getUserId() : null;
        }

        if (ContentReport::CONTENT_TYPE_FILE === $contentType) {
            $file = $this->fileRepository->find($contentId);
            $ownerId = null !== $file ? $file->getUserId() : null;

            return null !== $ownerId && $ownerId > 0 ? $ownerId : null;
        }

        return null;
    }
}
