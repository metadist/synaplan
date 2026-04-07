<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use PHPUnit\Framework\TestCase;

class ModelPriceHistoryTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $entry = new ModelPriceHistory();

        $this->assertNull($entry->getId());
        $this->assertSame('0.00000000', $entry->getPriceIn());
        $this->assertSame('0.00000000', $entry->getPriceOut());
        $this->assertSame('per1M', $entry->getInUnit());
        $this->assertSame('per1M', $entry->getOutUnit());
        $this->assertNull($entry->getCachePriceIn());
        $this->assertSame('manual', $entry->getSource());
        $this->assertNull($entry->getValidTo());
        $this->assertTrue($entry->isCurrentlyValid());
        $this->assertInstanceOf(\DateTimeInterface::class, $entry->getValidFrom());
        $this->assertInstanceOf(\DateTimeInterface::class, $entry->getCreatedAt());
    }

    public function testSettersAndGetters(): void
    {
        $model = $this->createMock(Model::class);
        $validFrom = new \DateTimeImmutable('2025-01-01');
        $validTo = new \DateTimeImmutable('2025-06-01');

        $entry = new ModelPriceHistory();
        $entry->setModel($model)
            ->setPriceIn('3.50000000')
            ->setPriceOut('15.00000000')
            ->setInUnit('per1K')
            ->setOutUnit('per1K')
            ->setCachePriceIn('0.50000000')
            ->setSource('litellm')
            ->setValidFrom($validFrom)
            ->setValidTo($validTo);

        $this->assertSame($model, $entry->getModel());
        $this->assertSame('3.50000000', $entry->getPriceIn());
        $this->assertSame('15.00000000', $entry->getPriceOut());
        $this->assertSame('per1K', $entry->getInUnit());
        $this->assertSame('per1K', $entry->getOutUnit());
        $this->assertSame('0.50000000', $entry->getCachePriceIn());
        $this->assertSame('litellm', $entry->getSource());
        $this->assertSame($validFrom, $entry->getValidFrom());
        $this->assertSame($validTo, $entry->getValidTo());
    }

    public function testIsCurrentlyValidReturnsFalseWhenClosed(): void
    {
        $entry = new ModelPriceHistory();
        $entry->setValidTo(new \DateTimeImmutable());

        $this->assertFalse($entry->isCurrentlyValid());
    }

    public function testIsCurrentlyValidReturnsTrueWhenOpen(): void
    {
        $entry = new ModelPriceHistory();

        $this->assertTrue($entry->isCurrentlyValid());
    }

    public function testFluentInterface(): void
    {
        $model = $this->createMock(Model::class);
        $entry = new ModelPriceHistory();

        $result = $entry->setModel($model);
        $this->assertSame($entry, $result);

        $result = $entry->setPriceIn('1.0');
        $this->assertSame($entry, $result);

        $result = $entry->setPriceOut('2.0');
        $this->assertSame($entry, $result);

        $result = $entry->setSource('admin');
        $this->assertSame($entry, $result);
    }
}
