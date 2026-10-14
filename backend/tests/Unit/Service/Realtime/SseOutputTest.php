<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Realtime;

use App\Service\Realtime\SseOutput;
use PHPUnit\Framework\TestCase;

final class SseOutputTest extends TestCase
{
    public function testCaptureModeKeepsTheBufferTheTestIsWritingInto(): void
    {
        ob_start();
        echo 'before';

        SseOutput::begin(true);
        echo '-after';

        $captured = ob_get_clean();

        self::assertSame('before-after', $captured);
    }
}
