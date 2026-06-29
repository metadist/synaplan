<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\NativeAuthHandoffService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class NativeAuthHandoffServiceTest extends TestCase
{
    public function testValidateReturnsUserIdForFreshToken(): void
    {
        $service = $this->service();

        $token = $service->generate($this->user(42));

        $this->assertSame(42, $service->validate($token));
    }

    public function testTokenIsSingleUse(): void
    {
        // Same service instance → same cache, so the nonce is remembered.
        $service = $this->service();
        $token = $service->generate($this->user(7));

        $this->assertSame(7, $service->validate($token), 'first use should succeed');
        $this->assertNull($service->validate($token), 'replay must be rejected');
    }

    public function testRejectsTamperedToken(): void
    {
        $service = $this->service();
        $token = $service->generate($this->user(1));

        $this->assertNull($service->validate($token.'tampered'));
    }

    public function testRejectsForeignSignature(): void
    {
        $signer = new NativeAuthHandoffService(new NullLogger(), new ArrayAdapter(), 'secret-a');
        $verifier = new NativeAuthHandoffService(new NullLogger(), new ArrayAdapter(), 'secret-b');

        $token = $signer->generate($this->user(1));

        $this->assertNull($verifier->validate($token));
    }

    public function testRejectsMalformedToken(): void
    {
        $this->assertNull($this->service()->validate('not-a-valid-token'));
    }

    private function service(): NativeAuthHandoffService
    {
        return new NativeAuthHandoffService(new NullLogger(), new ArrayAdapter(), 'test-secret');
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
