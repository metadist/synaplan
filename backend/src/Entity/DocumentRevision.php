<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRevisionRepository::class)]
#[ORM\Table(name: 'BDOCUMENT_REVISIONS')]
#[ORM\Index(columns: ['BFILEID', 'BVERSION'], name: 'idx_docrev_file_version')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_docrev_user')]
class DocumentRevision
{
    public const SOURCE_MODEL = 'model';
    public const SOURCE_BINARY = 'binary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BFILEID', type: 'bigint')]
    private int $fileId = 0;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId = 0;

    #[ORM\Column(name: 'BVERSION', type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'BSCHEMAVERSION', type: 'integer', options: ['default' => 1])]
    private int $schemaVersion = 1;

    #[ORM\Column(name: 'BMODEL', type: 'text')]
    private string $model = '{}';

    /** length 65535 pins the DBAL comparator to TEXT — the migration DDL — instead of LONGTEXT. */
    #[ORM\Column(name: 'BSUMMARY', type: 'text', length: 65535)]
    private string $summary = '';

    #[ORM\Column(name: 'BSOURCE', length: 16, options: ['default' => self::SOURCE_MODEL])]
    private string $source = self::SOURCE_MODEL;

    #[ORM\Column(name: 'BBINARYSHA', length: 64, nullable: true)]
    private ?string $binarySha = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileId(): int
    {
        return $this->fileId;
    }

    public function setFileId(int $fileId): self
    {
        $this->fileId = $fileId;

        return $this;
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function setSchemaVersion(int $schemaVersion): self
    {
        $this->schemaVersion = $schemaVersion;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getBinarySha(): ?string
    {
        return $this->binarySha;
    }

    public function setBinarySha(?string $binarySha): self
    {
        $this->binarySha = $binarySha;

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
}
