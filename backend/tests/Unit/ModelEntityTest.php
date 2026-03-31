<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Model;
use PHPUnit\Framework\TestCase;

class ModelEntityTest extends TestCase
{
    public function testIsHiddenBecauseFreeWhenBothPricesZeroAndNoOverride(): void
    {
        $model = new Model();
        $model->setPriceIn(0.0);
        $model->setPriceOut(0.0);
        $model->setShowWhenFree(0);

        $this->assertTrue($model->isHiddenBecauseFree());
    }

    public function testIsNotHiddenWhenShowWhenFreeOverrideEnabled(): void
    {
        $model = new Model();
        $model->setPriceIn(0.0);
        $model->setPriceOut(0.0);
        $model->setShowWhenFree(1);

        $this->assertFalse($model->isHiddenBecauseFree());
    }

    public function testIsNotHiddenWhenPriceInIsNonZero(): void
    {
        $model = new Model();
        $model->setPriceIn(1.50);
        $model->setPriceOut(0.0);
        $model->setShowWhenFree(0);

        $this->assertFalse($model->isHiddenBecauseFree());
    }

    public function testIsNotHiddenWhenPriceOutIsNonZero(): void
    {
        $model = new Model();
        $model->setPriceIn(0.0);
        $model->setPriceOut(2.00);
        $model->setShowWhenFree(0);

        $this->assertFalse($model->isHiddenBecauseFree());
    }

    public function testIsNotHiddenWhenBothPricesAreNonZero(): void
    {
        $model = new Model();
        $model->setPriceIn(1.50);
        $model->setPriceOut(2.00);
        $model->setShowWhenFree(0);

        $this->assertFalse($model->isHiddenBecauseFree());
    }

    public function testShowWhenFreeDefaultsToZero(): void
    {
        $model = new Model();

        $this->assertSame(0, $model->getShowWhenFree());
    }
}
