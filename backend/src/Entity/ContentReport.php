<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's report of objectionable user-generated content (Apple Guideline 1.2).
 *
 * The report targets a piece of content (a chat message or a file) by type + id.
 * The content owner is resolved server-side into BREPORTEDUSERID so operators
 * can act on repeat offenders without trusting client-supplied ownership.
 */
#[ORM\Entity(repositoryClass: ContentReportRepository::class)]
#[ORM\Table(name: 'BCONTENT_REPORTS')]
#[ORM\Index(columns: ['BSTATUS'], name: 'IDX_CONTENT_REPORT_STATUS')]
#[ORM\Index(columns: ['BREPORTERID'], name: 'IDX_CONTENT_REPORT_REPORTER')]
#[ORM\Index(columns: ['BREPORTEDUSERID'], name: 'IDX_CONTENT_REPORT_REPORTED_USER')]
class ContentReport
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_ACTIONED = 'actioned';
    public const STATUS_DISMISSED = 'dismissed';

    public const CONTENT_TYPE_MESSAGE = 'message';
    public const CONTENT_TYPE_FILE = 'file';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BREPORTERID', type: 'bigint')]
    private int $reporterId = 0;

    #[ORM\Column(name: 'BCONTENTTYPE', length: 24)]
    private string $contentType = self::CONTENT_TYPE_MESSAGE;

    #[ORM\Column(name: 'BCONTENTID', type: 'bigint')]
    private int $contentId = 0;

    #[ORM\Column(name: 'BREPORTEDUSERID', type: 'bigint', nullable: true)]
    private ?int $reportedUserId = null;

    #[ORM\Column(name: 'BREASON', length: 48)]
    private string $reason = '';

    #[ORM\Column(name: 'BDETAILS', type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(name: 'BSTATUS', length: 24, options: ['default' => 'open'])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'BCREATED', length: 20)]
    private string $created = '';

    #[ORM\Column(name: 'BREVIEWEDBY', type: 'bigint', nullable: true)]
    private ?int $reviewedBy = null;

    #[ORM\Column(name: 'BREVIEWEDAT', length: 20, nullable: true)]
    private ?string $reviewedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReporterId(): int
    {
        return $this->reporterId;
    }

    public function setReporterId(int $reporterId): self
    {
        $this->reporterId = $reporterId;

        return $this;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function setContentType(string $contentType): self
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getContentId(): int
    {
        return $this->contentId;
    }

    public function setContentId(int $contentId): self
    {
        $this->contentId = $contentId;

        return $this;
    }

    public function getReportedUserId(): ?int
    {
        return $this->reportedUserId;
    }

    public function setReportedUserId(?int $reportedUserId): self
    {
        $this->reportedUserId = $reportedUserId;

        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): self
    {
        $this->details = $details;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function setCreated(string $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getReviewedBy(): ?int
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?int $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewedAt(): ?string
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?string $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }
}
