<?php

namespace App\Entity;

use App\Repository\WidgetSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WidgetSessionRepository::class)]
#[ORM\Table(name: 'BWIDGET_SESSIONS')]
#[ORM\UniqueConstraint(name: 'uk_widget_session', columns: ['BWIDGETID', 'BSESSIONID'])]
#[ORM\Index(columns: ['BWIDGETID'], name: 'idx_session_widget')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_session_expires')]
#[ORM\Index(columns: ['BMODE'], name: 'idx_session_mode')]
class WidgetSession
{
    public const MODE_AI = 'ai';
    public const MODE_HUMAN = 'human';
    public const MODE_WAITING = 'waiting';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64)]
    private string $widgetId;

    #[ORM\Column(name: 'BSESSIONID', length: 64)]
    private string $sessionId;

    #[ORM\Column(name: 'BMESSAGECOUNT', type: 'integer')]
    private int $messageCount = 0;

    #[ORM\Column(name: 'BFILECOUNT', type: 'integer')]
    private int $fileCount = 0;

    #[ORM\Column(name: 'BLASTMESSAGE', type: 'bigint')]
    private int $lastMessage = 0;

    #[ORM\Column(name: 'BCHATID', type: 'bigint', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    /**
     * Session mode: 'ai' (default), 'human' (operator takeover), 'waiting' (waiting for human response).
     * Nullable for backward compatibility with existing sessions.
     */
    #[ORM\Column(name: 'BMODE', length: 16, nullable: true, options: ['default' => 'ai'])]
    private ?string $mode = self::MODE_AI;

    /**
     * User ID of the human operator who took over the session.
     */
    #[ORM\Column(name: 'BHUMAN_OPERATOR_ID', type: 'bigint', nullable: true)]
    private ?int $humanOperatorId = null;

    /**
     * Unix timestamp of last human operator activity.
     */
    #[ORM\Column(name: 'BLAST_HUMAN_ACTIVITY', type: 'bigint', nullable: true)]
    private ?int $lastHumanActivity = null;

    /**
     * Preview of the last message (truncated to 200 chars).
     */
    #[ORM\Column(name: 'BLAST_MESSAGE_PREVIEW', type: 'string', length: 255, nullable: true)]
    private ?string $lastMessagePreview = null;

    /**
     * Whether this session is marked as favorite by the widget owner.
     * Nullable for backward compatibility with existing sessions.
     */
    #[ORM\Column(name: 'BIS_FAVORITE', type: 'boolean', nullable: true, options: ['default' => false])]
    private ?bool $isFavorite = false;

    /**
     * ISO 3166-1 Alpha-2 country code from Cloudflare geolocation (e.g., "DE", "US").
     * Null if not detected or if using Tor (T1) or unknown (XX).
     */
    #[ORM\Column(name: 'BCOUNTRY', type: 'string', length: 2, nullable: true)]
    private ?string $country = null;

    /**
     * AI-generated title summarizing the conversation (max 50 chars).
     * Generated after 5 user messages.
     */
    #[ORM\Column(name: 'BTITLE', type: 'string', length: 100, nullable: true)]
    private ?string $title = null;

    public function __construct()
    {
        $this->created = time();
        $this->expires = time() + 86400; // 24 hours
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

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

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

    public function incrementMessageCount(): self
    {
        ++$this->messageCount;

        return $this;
    }

    public function getFileCount(): int
    {
        return $this->fileCount;
    }

    public function setFileCount(int $fileCount): self
    {
        $this->fileCount = $fileCount;

        return $this;
    }

    public function incrementFileCount(): self
    {
        ++$this->fileCount;

        return $this;
    }

    public function getLastMessage(): int
    {
        return $this->lastMessage;
    }

    public function setLastMessage(int $lastMessage): self
    {
        $this->lastMessage = $lastMessage;
        $this->expires = $lastMessage + 86400; // Extend expiry by 24h

        return $this;
    }

    public function updateLastMessage(): self
    {
        return $this->setLastMessage(time());
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
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

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function setExpires(int $expires): self
    {
        $this->expires = $expires;

        return $this;
    }

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    /**
     * Check if this is a test session based on session ID prefix.
     * Test sessions have IDs starting with 'test_'.
     */
    public function isTest(): bool
    {
        return str_starts_with($this->sessionId, 'test_');
    }

    /**
     * Get messages sent in the last minute.
     */
    public function getMessagesInLastMinute(): int
    {
        // This will be tracked in a separate table or cache
        // For now, we'll implement rate limiting in the service
        return 0;
    }

    public function getMode(): string
    {
        // Fallback to 'ai' for backward compatibility with existing sessions
        return $this->mode ?? self::MODE_AI;
    }

    public function setMode(string $mode): self
    {
        if (!in_array($mode, [self::MODE_AI, self::MODE_HUMAN, self::MODE_WAITING], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid mode: %s', $mode));
        }
        $this->mode = $mode;

        return $this;
    }

    public function isAiMode(): bool
    {
        return self::MODE_AI === $this->getMode();
    }

    public function isHumanMode(): bool
    {
        return self::MODE_HUMAN === $this->getMode();
    }

    public function isWaitingForHuman(): bool
    {
        return self::MODE_WAITING === $this->getMode();
    }

    public function getHumanOperatorId(): ?int
    {
        return $this->humanOperatorId;
    }

    public function setHumanOperatorId(?int $humanOperatorId): self
    {
        $this->humanOperatorId = $humanOperatorId;

        return $this;
    }

    public function getLastHumanActivity(): ?int
    {
        return $this->lastHumanActivity;
    }

    public function setLastHumanActivity(?int $lastHumanActivity): self
    {
        $this->lastHumanActivity = $lastHumanActivity;

        return $this;
    }

    public function updateLastHumanActivity(): self
    {
        return $this->setLastHumanActivity(time());
    }

    public function getLastMessagePreview(): ?string
    {
        return $this->lastMessagePreview;
    }

    public function setLastMessagePreview(?string $preview): self
    {
        if (null !== $preview) {
            $preview = self::sanitizePreviewText($preview);
        }
        $this->lastMessagePreview = $preview;

        return $this;
    }

    /**
     * Sanitize text for use as a message preview.
     *
     * Converts Markdown to HTML via Parsedown, then strips all tags to produce
     * safe plaintext. Truncates to 200 chars on a word boundary to avoid
     * cutting mid-word.
     */
    private static function sanitizePreviewText(string $text, int $maxLength = 200): string
    {
        // Convert Markdown → HTML → plaintext (handles all MD features correctly)
        $html = (new \Parsedown())->setSafeMode(true)->text($text);
        $text = strip_tags($html);

        // Decode HTML entities (e.g. &amp; → &) to avoid truncating mid-entity
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse whitespace (newlines, tabs, multiple spaces → single space)
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Truncate on word boundary
        if (mb_strlen($text) > $maxLength) {
            $truncated = mb_substr($text, 0, $maxLength);
            // Try to break at last space to avoid cutting mid-word
            $lastSpace = mb_strrpos($truncated, ' ');
            if (false !== $lastSpace && $lastSpace > $maxLength * 0.7) {
                $truncated = mb_substr($truncated, 0, $lastSpace);
            }
            $text = $truncated.'…';
        }

        return $text;
    }

    /**
     * Take over the session with a human operator.
     */
    public function takeOver(int $operatorId): self
    {
        $this->mode = self::MODE_HUMAN;
        $this->humanOperatorId = $operatorId;
        $this->lastHumanActivity = time();

        return $this;
    }

    /**
     * Hand back the session to AI.
     */
    public function handBackToAi(): self
    {
        $this->mode = self::MODE_AI;
        // Keep humanOperatorId for history

        return $this;
    }

    /**
     * Set session to waiting for human response.
     */
    public function setWaitingForHuman(): self
    {
        $this->mode = self::MODE_WAITING;

        return $this;
    }

    public function isFavorite(): bool
    {
        // Fallback to false for backward compatibility with existing sessions
        return $this->isFavorite ?? false;
    }

    public function setIsFavorite(bool $isFavorite): self
    {
        $this->isFavorite = $isFavorite;

        return $this;
    }

    public function toggleFavorite(): self
    {
        $this->isFavorite = !$this->isFavorite();

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        // Filter out special Cloudflare codes (XX = unknown, T1 = Tor)
        if ('XX' === $country || 'T1' === $country || null === $country || '' === $country) {
            $this->country = null;
        } else {
            $this->country = strtoupper($country);
        }

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = null !== $title ? mb_substr($title, 0, 100) : null;

        return $this;
    }
}
