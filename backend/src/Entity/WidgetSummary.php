<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WidgetSummaryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores AI-generated summaries of widget chat sessions.
 */
#[ORM\Entity(repositoryClass: WidgetSummaryRepository::class)]
#[ORM\Table(name: 'BWIDGET_SUMMARIES')]
#[ORM\Index(columns: ['BWIDGETID'], name: 'idx_summary_widget')]
#[ORM\Index(columns: ['BDATE'], name: 'idx_summary_date')]
class WidgetSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64)]
    private string $widgetId;

    /**
     * Date of the summary (YYYYMMDD format for easy querying).
     */
    #[ORM\Column(name: 'BDATE', type: 'integer')]
    private int $date;

    /**
     * Number of sessions summarized.
     */
    #[ORM\Column(name: 'BSESSION_COUNT', type: 'integer')]
    private int $sessionCount = 0;

    /**
     * Number of messages summarized.
     */
    #[ORM\Column(name: 'BMESSAGE_COUNT', type: 'integer')]
    private int $messageCount = 0;

    /**
     * Main topics discussed (JSON array).
     */
    #[ORM\Column(name: 'BTOPICS', type: 'text')]
    private string $topics = '[]';

    /**
     * Frequently asked questions (JSON array).
     */
    #[ORM\Column(name: 'BFAQS', type: 'text')]
    private string $faqs = '[]';

    /**
     * Sentiment analysis (JSON: {positive: %, neutral: %, negative: %}).
     */
    #[ORM\Column(name: 'BSENTIMENT', type: 'text')]
    private string $sentiment = '{}';

    /**
     * Problematic issues or unresolved queries (JSON array).
     */
    #[ORM\Column(name: 'BISSUES', type: 'text')]
    private string $issues = '[]';

    /**
     * Recommendations for knowledge base improvements (JSON array).
     */
    #[ORM\Column(name: 'BRECOMMENDATIONS', type: 'text')]
    private string $recommendations = '[]';

    /**
     * Full AI-generated summary text.
     */
    #[ORM\Column(name: 'BSUMMARY_TEXT', type: 'text')]
    private string $summaryText = '';

    /**
     * Prompt improvement suggestions (JSON array).
     */
    #[ORM\Column(name: 'BPROMPT_SUGGESTIONS', type: 'text', nullable: true)]
    private ?string $promptSuggestions = null;

    /**
     * Start date of the analysis period (YYYYMMDD format).
     */
    #[ORM\Column(name: 'BFROM_DATE', type: 'integer', nullable: true)]
    private ?int $fromDate = null;

    /**
     * End date of the analysis period (YYYYMMDD format).
     */
    #[ORM\Column(name: 'BTO_DATE', type: 'integer', nullable: true)]
    private ?int $toDate = null;

    /**
     * When the summary was generated (Unix timestamp).
     */
    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct()
    {
        $this->created = time();
        $this->date = (int) date('Ymd');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function setWidgetId(string $widgetId): self
    {
        $this->widgetId = $widgetId;

        return $this;
    }

    public function getDate(): int
    {
        return $this->date;
    }

    public function setDate(int $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getSessionCount(): int
    {
        return $this->sessionCount;
    }

    public function setSessionCount(int $sessionCount): self
    {
        $this->sessionCount = $sessionCount;

        return $this;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): self
    {
        $this->messageCount = $messageCount;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getTopics(): array
    {
        return json_decode($this->topics, true) ?? [];
    }

    /**
     * @param array<string> $topics
     */
    public function setTopics(array $topics): self
    {
        $this->topics = json_encode($topics, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    /**
     * @return array<array{question: string, frequency: int}>
     */
    public function getFaqs(): array
    {
        return json_decode($this->faqs, true) ?? [];
    }

    /**
     * @param array<array{question: string, frequency: int}> $faqs
     */
    public function setFaqs(array $faqs): self
    {
        $this->faqs = json_encode($faqs, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    /**
     * @return array{positive: float, neutral: float, negative: float}
     */
    public function getSentiment(): array
    {
        return json_decode($this->sentiment, true) ?? ['positive' => 0, 'neutral' => 100, 'negative' => 0];
    }

    /**
     * @param array{positive: float, neutral: float, negative: float} $sentiment
     */
    public function setSentiment(array $sentiment): self
    {
        $this->sentiment = json_encode($sentiment, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getIssues(): array
    {
        return json_decode($this->issues, true) ?? [];
    }

    /**
     * @param array<string> $issues
     */
    public function setIssues(array $issues): self
    {
        $this->issues = json_encode($issues, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getRecommendations(): array
    {
        return json_decode($this->recommendations, true) ?? [];
    }

    /**
     * @param array<string> $recommendations
     */
    public function setRecommendations(array $recommendations): self
    {
        $this->recommendations = json_encode($recommendations, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function getSummaryText(): string
    {
        return $this->summaryText;
    }

    public function setSummaryText(string $summaryText): self
    {
        $this->summaryText = $summaryText;

        return $this;
    }

    /**
     * @return array<array{type: string, suggestion: string}>
     */
    public function getPromptSuggestions(): array
    {
        return $this->promptSuggestions ? (json_decode($this->promptSuggestions, true) ?? []) : [];
    }

    /**
     * @param array<array{type: string, suggestion: string}> $suggestions
     */
    public function setPromptSuggestions(array $suggestions): self
    {
        $this->promptSuggestions = json_encode($suggestions, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function getFromDate(): ?int
    {
        return $this->fromDate;
    }

    public function setFromDate(?int $fromDate): self
    {
        $this->fromDate = $fromDate;

        return $this;
    }

    public function getToDate(): ?int
    {
        return $this->toDate;
    }

    public function setToDate(?int $toDate): self
    {
        $this->toDate = $toDate;

        return $this;
    }

    /**
     * Get formatted date range string.
     */
    public function getFormattedDateRange(): ?string
    {
        if (!$this->fromDate || !$this->toDate) {
            return null;
        }

        $formatDate = function (int $date): string {
            $year = (int) substr((string) $date, 0, 4);
            $month = (int) substr((string) $date, 4, 2);
            $day = (int) substr((string) $date, 6, 2);

            return sprintf('%02d.%02d.%04d', $day, $month, $year);
        };

        return $formatDate($this->fromDate).' - '.$formatDate($this->toDate);
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get formatted date string.
     */
    public function getFormattedDate(): string
    {
        $year = (int) substr((string) $this->date, 0, 4);
        $month = (int) substr((string) $this->date, 4, 2);
        $day = (int) substr((string) $this->date, 6, 2);

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
