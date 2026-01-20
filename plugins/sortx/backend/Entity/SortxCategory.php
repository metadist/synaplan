<?php

declare(strict_types=1);

namespace Plugin\SortX\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Plugin\SortX\Repository\SortxCategoryRepository;

#[ORM\Entity(repositoryClass: SortxCategoryRepository::class)]
#[ORM\Table(name: 'sortx_categories')]
#[ORM\UniqueConstraint(name: 'idx_user_key', columns: ['user_id', 'category_key'])]
#[ORM\Index(name: 'idx_user_enabled', columns: ['user_id', 'enabled'])]
#[ORM\HasLifecycleCallbacks]
class SortxCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'category_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SortxCategoryField> */
    #[ORM\OneToMany(targetEntity: SortxCategoryField::class, mappedBy: 'category', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $fields;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $this->sanitizeKey($key);

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, SortxCategoryField> */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(SortxCategoryField $field): self
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setCategory($this);
        }

        return $this;
    }

    public function removeField(SortxCategoryField $field): self
    {
        $this->fields->removeElement($field);

        return $this;
    }

    /**
     * Sanitize key to alphanumeric + underscore only (security: prevent injection).
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key))) ?? '';
    }
}
