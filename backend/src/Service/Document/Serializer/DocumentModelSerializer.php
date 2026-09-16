<?php

declare(strict_types=1);

namespace App\Service\Document\Serializer;

use App\Service\Document\DocumentKind;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;

/**
 * Model ↔ JSON with schemaVersion for later migrations.
 */
final class DocumentModelSerializer
{
    public function encode(SpreadsheetModel|WordModel|DeckModel $model): string
    {
        $json = json_encode($model->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return $json;
    }

    public function decode(string $json): SpreadsheetModel|WordModel|DeckModel
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Document model JSON must be an object');
        }

        return $this->fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): SpreadsheetModel|WordModel|DeckModel
    {
        $kind = is_string($data['kind'] ?? null) ? $data['kind'] : '';
        if (DocumentKind::XLSX === $kind) {
            return SpreadsheetModel::fromArray($data);
        }
        if (DocumentKind::DOCX === $kind) {
            return WordModel::fromArray($data);
        }
        if (DocumentKind::PPTX === $kind) {
            return DeckModel::fromArray($data);
        }

        throw new \InvalidArgumentException(sprintf('Unknown document kind "%s"', $kind));
    }
}
