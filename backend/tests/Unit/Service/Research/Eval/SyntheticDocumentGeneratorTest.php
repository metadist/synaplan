<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Research\Eval\SyntheticDocumentGenerator;
use PHPUnit\Framework\TestCase;

final class SyntheticDocumentGeneratorTest extends TestCase
{
    public function testTheSameBytesAreProducedEveryTime(): void
    {
        $generator = new SyntheticDocumentGenerator();
        $first = $generator->documents();
        $second = $generator->documents();

        $this->assertSame($first, $second);
        $this->assertSame(SyntheticDocumentGenerator::CHECKSUM, $generator->checksum());
        $this->assertArrayHasKey('rahmenvertrag.txt', $first);
        $this->assertStringContainsString('ZEBRA-41', $first['rahmenvertrag.txt']);
        $this->assertStringContainsString('1500 EUR', $first['preisliste.txt']);
        $this->assertStringContainsString('14. März', $first['protokoll.txt']);
    }

    public function testWritingTheFilesMatchesTheChecksum(): void
    {
        $dir = sys_get_temp_dir().'/research-docs-'.bin2hex(random_bytes(4));
        $generator = new SyntheticDocumentGenerator();
        $generator->write($dir);

        try {
            $parts = [];
            foreach ($generator->documents() as $name => $body) {
                $this->assertSame($body, (string) file_get_contents($dir.'/'.$name));
                $parts[] = $name."\n".$body;
            }
            $this->assertSame(SyntheticDocumentGenerator::CHECKSUM, hash('sha256', implode("\n", $parts)));
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
