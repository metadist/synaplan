<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Service\Multitask\Execution\StructuredField;
use PHPUnit\Framework\TestCase;

final class StructuredFieldTest extends TestCase
{
    public function testReadsTopLevelFieldFirst(): void
    {
        self::assertSame('top', StructuredField::from('{"id":"top","results":[{"id":"row"}]}', 'id'));
    }

    public function testFallsBackToFirstRowAndToIdForIdFields(): void
    {
        $json = '{"results":[{"id":"a1","title":"First"},{"id":"b2","title":"Second"}]}';

        self::assertSame('a1', StructuredField::from($json, 'page_id'));
        self::assertSame('a1', StructuredField::from($json, 'pageId'));
        self::assertSame('First', StructuredField::from($json, 'title'));
        self::assertSame('a1', StructuredField::from('[{"id":"a1"}]', 'id'));
    }

    public function testReturnsNullForProseOrMissingField(): void
    {
        self::assertNull(StructuredField::from('just text', 'id'));
        self::assertNull(StructuredField::from('{"results":[]}', 'page_id'));
        self::assertNull(StructuredField::from('{"results":[{"title":"x"}]}', 'page_id'));
    }

    public function testDetectsUnresolvedPlaceholdersInNestedArguments(): void
    {
        self::assertTrue(StructuredField::hasUnresolved(['page_id' => '$n1.page_id']));
        self::assertTrue(StructuredField::hasUnresolved(['properties' => ['title' => '${n2.title}']]));
        self::assertFalse(StructuredField::hasUnresolved(['page_id' => '39797ad6', 'price' => '$5.00', 'note' => 'costs $n1.page_id today']));
    }
}
