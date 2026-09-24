<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ModelDiscoveryIgnoreList;
use PHPUnit\Framework\TestCase;

final class ModelDiscoveryIgnoreListTest extends TestCase
{
    public function testEveryEntryHasNonEmptyReasonAndIsoDate(): void
    {
        $this->assertNotEmpty(ModelDiscoveryIgnoreList::ENTRIES);

        foreach (ModelDiscoveryIgnoreList::ENTRIES as $id => $entry) {
            $this->assertIsString($id);
            $this->assertNotSame('', $id);
            $this->assertArrayHasKey('reason', $entry);
            $this->assertArrayHasKey('decidedOn', $entry);
            $this->assertIsString($entry['reason']);
            $this->assertNotSame('', trim($entry['reason']));
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $entry['decidedOn'],
                sprintf('Ignore entry "%s" decidedOn must be YYYY-MM-DD', $id),
            );
        }
    }
}
