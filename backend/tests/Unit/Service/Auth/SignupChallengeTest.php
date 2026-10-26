<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\RecaptchaService;
use App\Service\Auth\SignupChallenge;
use App\Service\Auth\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SignupChallengeTest extends TestCase
{
    public function testNoProviderMeansNoCheckAndEveryTokenPasses(): void
    {
        $challenge = new SignupChallenge($this->turnstile(''), $this->recaptcha(false));

        $this->assertNull($challenge->provider());
        $this->assertFalse($challenge->isRequired());
        $this->assertTrue($challenge->verify(null, 'register', '1.2.3.4'));
        $this->assertTrue($challenge->verify('', 'login', null));
    }

    public function testTurnstileWinsOverRecaptchaAndIsAskedForTheVerdict(): void
    {
        $challenge = new SignupChallenge(
            $this->turnstile('0xSECRET', new MockResponse(json_encode(['success' => false], JSON_THROW_ON_ERROR))),
            $this->recaptcha(true),
        );

        $this->assertSame(SignupChallenge::PROVIDER_TURNSTILE, $challenge->provider());
        $this->assertTrue($challenge->isRequired());
        $this->assertFalse($challenge->verify('tok', 'register', '1.2.3.4'), 'reCAPTCHA being "enabled" must not rescue a token Turnstile refused');
        $this->assertFalse($challenge->verify(null, 'register', '1.2.3.4'), 'A missing token fails closed');
    }

    public function testRecaptchaIsTheFallbackProvider(): void
    {
        $challenge = new SignupChallenge($this->turnstile(''), $this->recaptcha(true));

        $this->assertSame(SignupChallenge::PROVIDER_RECAPTCHA, $challenge->provider());
        $this->assertTrue($challenge->isRequired());
    }

    private function turnstile(string $secret, ?MockResponse $response = null): TurnstileVerifier
    {
        return new TurnstileVerifier(new MockHttpClient($response ?? new MockResponse('{}')), new NullLogger(), $secret);
    }

    private function recaptcha(bool $enabled): RecaptchaService
    {
        return new RecaptchaService(new NullLogger(), $enabled ? 'valid_secret_key' : '', $enabled ? 'true' : 'false', '0.5');
    }
}
