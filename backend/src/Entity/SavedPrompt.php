<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedPromptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedPromptRepository::class)]
#[ORM\Table(name: 'BSAVEDPROMPTS')]
#[ORM\UniqueConstraint(name: 'uniq_saved_prompt_user_command', columns: ['BUSERID', 'BCOMMAND'])]
class SavedPrompt
{
    public const RESERVED_COMMANDS = ['search', 'pic', 'vid', 'tts', 'help'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BNAME', type: 'string', length: 120)]
    private string $name = '';

    #[ORM\Column(name: 'BCOMMAND', type: 'string', length: 64)]
    private string $command = '';

    #[ORM\Column(name: 'BBODY', type: 'text')]
    private string $body = '';

    #[ORM\Column(name: 'BTAGS', type: 'string', length: 255, options: ['default' => ''])]
    private string $tags = '';

    #[ORM\Column(name: 'BCREATEDAT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'BUPDATEDAT', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): self
    {
        $this->command = self::normalizeCommand($command);

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTags(): array
    {
        if ('' === trim($this->tags)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->tags))));
    }

    /**
     * @param list<string> $tags
     */
    public function setTags(array $tags): self
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim($tag));
            if ('' !== $tag && !in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }
        $this->tags = implode(',', array_slice($clean, 0, 8));

        return $this;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTime();

        return $this;
    }

    public static function normalizeCommand(string $command): string
    {
        $command = strtolower(trim($command));
        $command = ltrim($command, '/');

        return preg_replace('/[^a-z0-9_-]+/', '', $command) ?? '';
    }

    public static function commandError(string $command): ?string
    {
        if ('' === $command || strlen($command) > 64) {
            return 'Choose a short command using letters, numbers, and dashes.';
        }
        if (in_array($command, self::RESERVED_COMMANDS, true)) {
            return 'That command is already used by a built-in action.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', $this->body, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'command' => $this->command,
            'body' => $this->body,
            'tags' => $this->getTags(),
            'variables' => $this->variables(),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}
