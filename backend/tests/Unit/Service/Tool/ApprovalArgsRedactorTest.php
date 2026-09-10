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

    public function testMasksCredentialLookingValuesButKeepsProse(): void
    {
        $redactor = new ApprovalArgsRedactor();
        $redacted = $redactor->redact([
            'header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
            'key' => 'sk_live_'.str_repeat('a', 40),
            'body' => 'Please reset the password for user 42 and send the token by mail.',
            'count' => 3,
        ]);
        $this->assertSame(ApprovalArgsRedactor::MASK, $redacted['header']);
        $this->assertSame(ApprovalArgsRedactor::MASK, $redacted['key']);
        $this->assertSame('Please reset the password for user 42 and send the token by mail.', $redacted['body']);
        $this->assertSame(3, $redacted['count']);
    }
}
