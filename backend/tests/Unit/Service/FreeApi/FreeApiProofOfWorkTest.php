<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Service\FreeApi\FreeApiProofOfWork;
use PHPUnit\Framework\TestCase;

final class FreeApiProofOfWorkTest extends TestCase
{
    private const INSTALL_ID = '0b5c3e2a-6a47-4b8e-9b1d-3f1f2f3a4b5c';

    public function testSolvedNonceVerifiesAndHasTheLeadingZeroBits(): void
    {
        $nonce = FreeApiProofOfWork::solve(self::INSTALL_ID, 12);

        $this->assertNotNull($nonce);
        $this->assertTrue(FreeApiProofOfWork::verify(self::INSTALL_ID, $nonce, 12));
        $hash = hash('sha256', self::INSTALL_ID.':'.$nonce);
        $this->assertStringStartsWith('000', $hash, '12 zero bits are three zero hex digits');
    }

    public function testTheNonceIsBoundToTheInstallId(): void
    {
        $nonce = FreeApiProofOfWork::solve(self::INSTALL_ID, 16);
        $this->assertNotNull($nonce);

        $this->assertFalse(FreeApiProofOfWork::verify('11111111-2222-4333-8444-555555555555', $nonce, 16));
    }

    public function testVerifyMatchesTheServerRuleForPartialBytes(): void
    {
        // sha256('x:1') starts with 0x0b = 0000 1011: exactly four leading zero bits.
        $this->assertSame('0b', substr(hash('sha256', 'x:1'), 0, 2));
        $this->assertTrue(FreeApiProofOfWork::verify('x', '1', 4));
        $this->assertFalse(FreeApiProofOfWork::verify('x', '1', 5));
        $this->assertFalse(FreeApiProofOfWork::verify('x', '0', 1), '0xbb has no leading zero bit');
    }

    public function testOutOfRangeBitsAreRefused(): void
    {
        $this->assertNull(FreeApiProofOfWork::solve(self::INSTALL_ID, 0));
        $this->assertNull(FreeApiProofOfWork::solve(self::INSTALL_ID, FreeApiProofOfWork::MAX_BITS + 1));
    }

    public function testTheTimeBudgetStopsTheSearch(): void
    {
        $this->assertNull(FreeApiProofOfWork::solve(self::INSTALL_ID, FreeApiProofOfWork::MAX_BITS, 0.0));
    }
}
