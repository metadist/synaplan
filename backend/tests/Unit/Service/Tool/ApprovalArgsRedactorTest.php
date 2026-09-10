<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Service\Tool\ApprovalArgsRedactor;
use PHPUnit\Framework\TestCase;

final class ApprovalArgsRedactorTest extends TestCase
{
    public function testDropsTokenLikeKeysAndCredentialValues(): void
    {
        $redactor = new ApprovalArgsRedactor();
        $redacted = $redactor->redact([
            'title' => 'Reset password',
            'token' => 'abc',
            'api_secret' => 'nope',
            'authorization' => 'Bearer xyz',
            'nested' => ['password' => 'x', 'ok' => 1],
        ]);
        $this->assertSame(['title' => 'Reset password', 'nested' => ['ok' => 1]], $redacted);
        $preview = $redactor->preview('Create ticket', ['token' => 'abc', 'title' => 'Hello']);
        $this->assertStringContainsString('Create ticket', $preview);
        $this->assertStringNotContainsString('abc', $preview);
    }
}
