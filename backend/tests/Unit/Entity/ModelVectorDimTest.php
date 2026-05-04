<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Model;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Model} accessors that read structured metadata
 * out of `BJSON`. Catalog metadata is the single source of truth for
 * embedding dimensions (PR #853 review fix), so changes to the
 * fallback logic must stay covered.
 */
final class ModelVectorDimTest extends TestCase
{
    public function testReturnsDefaultWhenJsonHasNoDimensions(): void
    {
        $model = new Model();
        $model->setJson([]);

        self::assertSame(1024, $model->getVectorDim());
    }

    public function testReturnsDefaultWhenMetaIsMissing(): void
    {
        $model = new Model();
        $model->setJson(['features' => ['embedding']]);

        self::assertSame(1024, $model->getVectorDim());
    }

    public function testReturnsCatalogDimensionWhenSet(): void
    {
        $model = new Model();
        $model->setJson(['meta' => ['dimensions' => 3072]]);

        self::assertSame(3072, $model->getVectorDim());
    }

    public function testParsesNumericStringDimension(): void
    {
        $model = new Model();
        $model->setJson(['meta' => ['dimensions' => '1536']]);

        self::assertSame(1536, $model->getVectorDim());
    }

    public function testFallsBackOnNonNumericDimension(): void
    {
        $model = new Model();
        $model->setJson(['meta' => ['dimensions' => 'not-a-number']]);

        self::assertSame(1024, $model->getVectorDim());
    }

    public function testFallsBackOnZeroOrNegativeDimension(): void
    {
        $model = new Model();
        $model->setJson(['meta' => ['dimensions' => 0]]);
        self::assertSame(1024, $model->getVectorDim());

        $model->setJson(['meta' => ['dimensions' => -512]]);
        self::assertSame(1024, $model->getVectorDim());
    }
}
