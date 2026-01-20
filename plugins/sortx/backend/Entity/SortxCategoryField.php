<?php

declare(strict_types=1);

namespace Plugin\SortX\Entity;

use Doctrine\ORM\Mapping as ORM;
use Plugin\SortX\Repository\SortxCategoryFieldRepository;

#[ORM\Entity(repositoryClass: SortxCategoryFieldRepository::class)]
#[ORM\Table(name: 'sortx_category_fields')]
#[ORM\UniqueConstraint(name: 'idx_category_field', columns: ['category_id', 'field_key'])]
#[ORM\HasLifecycleCallbacks]
class SortxCategoryField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_DATE = 'date';
    public const TYPE_NUMBER = 'number';
    public const TYPE_ENUM = 'enum';
    public const TYPE_BOOLEAN = 'boolean';

    public const VALID_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_DATE,
        self::TYPE_NUMBER,
        self::TYPE_ENUM,
        self::TYPE_BOOLEAN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SortxCategory::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(name: 'category_id', nullable: false, onDelete: 'CASCADE')]
    private SortxCategory $category;

    #[ORM\Column(name: 'field_key', length: 64)]
    private string $fieldKey;

    #[ORM\Column(name: 'field_name', length: 128)]
    private string $fieldName;

    #[ORM\Column(name: 'field_type', length: 32)]
    private string $fieldType = self::TYPE_TEXT;

    /** @var string[]|null */
    #[ORM\Column(name: 'enum_values', type: 'json', nullable: true)]
    private ?array $enumValues = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $required = false;

    #[ORM\Column(name: 'sort_order', type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
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

    public function getCategory(): SortxCategory
    {
        return $this->category;
    }

    public function setCategory(SortxCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getFieldKey(): string
    {
        return $this->fieldKey;
    }

    public function setFieldKey(string $fieldKey): self
    {
        $this->fieldKey = $this->sanitizeKey($fieldKey);

        return $this;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function setFieldName(string $fieldName): self
    {
        $this->fieldName = trim($fieldName);

        return $this;
    }

    public function getFieldType(): string
    {
        return $this->fieldType;
    }

    public function setFieldType(string $fieldType): self
    {
        if (!in_array($fieldType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid field type: $fieldType");
        }
        $this->fieldType = $fieldType;

        return $this;
    }

    /** @return string[]|null */
    public function getEnumValues(): ?array
    {
        return $this->enumValues;
    }

    /** @param string[]|null $enumValues */
    public function setEnumValues(?array $enumValues): self
    {
        if ($enumValues !== null) {
            // Sanitize enum values (prevent XSS)
            $enumValues = array_map(fn ($v) => htmlspecialchars(trim((string) $v), ENT_QUOTES, 'UTF-8'), $enumValues);
            $enumValues = array_values(array_filter($enumValues));
        }
        $this->enumValues = $enumValues;

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

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;

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

    /**
     * Sanitize key to alphanumeric + underscore only (security: prevent injection).
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key))) ?? '';
    }
}
