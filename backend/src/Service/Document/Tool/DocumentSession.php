<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;

/**
 * In-memory document for one tool turn. Persist only after the loop ends.
 */
final class DocumentSession
{
    /** @var list<DocumentToolResult> */
    public array $operations = [];

    public function __construct(
        public SpreadsheetModel|WordModel|DeckModel $model,
        public ?int $fileId = null,
        public ?string $filename = null,
    ) {
    }

    public function kind(): string
    {
        return $this->model->kind();
    }

    public function spreadsheet(): ?SpreadsheetModel
    {
        return $this->model instanceof SpreadsheetModel ? $this->model : null;
    }

    public function word(): ?WordModel
    {
        return $this->model instanceof WordModel ? $this->model : null;
    }

    public function deck(): ?DeckModel
    {
        return $this->model instanceof DeckModel ? $this->model : null;
    }

    public function record(DocumentToolResult $result): void
    {
        $this->operations[] = $result;
    }

    public function hasMutations(): bool
    {
        foreach ($this->operations as $op) {
            if ($op->ok && $op->mutates) {
                return true;
            }
        }

        return false;
    }

    public static function empty(string $kind, ?string $filename = null): self
    {
        $model = match ($kind) {
            DocumentKind::DOCX => WordModel::empty(),
            DocumentKind::PPTX => DeckModel::empty(),
            default => SpreadsheetModel::empty(),
        };

        return new self($model, null, $filename);
    }
}
