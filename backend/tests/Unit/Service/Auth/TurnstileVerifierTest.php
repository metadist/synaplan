<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TurnstileVerifierTest extends TestCase
{
    public function testNotConfiguredWithoutASecret(): void
    {
        $verifier = new TurnstileVerifier(new MockHttpClient(), new NullLogger(), '  ');

        $this->assertFalse($verifier->isConfigured());
        $this->assertFalse($verifier->verify('token', '1.2.3.4'), 'Unconfigured never verifies; SignupChallenge decides whether a check is required at all');
    }

    public function testAcceptsOnlyAnExplicitSuccessFromCloudflare(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $url);
            $this->assertStringContainsString('secret=0xSECRET', $options['body']);
            $this->assertStringContainsString('response=tok_1', $options['body']);
            $this->assertStringContainsString('remoteip=203.0.113.9', $options['body']);

            return new MockResponse(json_encode(['success' => true, 'action' => 'register'], JSON_THROW_ON_ERROR));
        });

        $verifier = new TurnstileVerifier($client, new NullLogger(), '0xSECRET');

        $this->assertTrue($verifier->isConfigured());
        $this->assertTrue($verifier->verify('tok_1', '203.0.113.9'));
    }

    public function testRejectsWhenCloudflareSaysNo(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['success' => false, 'error-codes' => ['invalid-input-response']], JSON_THROW_ON_ERROR)));

        $this->assertFalse((new TurnstileVerifier($client, new NullLogger(), '0xSECRET'))->verify('tok_bad', null));
    }

    public function testFailsClosedOnEmptyTokenNetworkErrorAndGarbage(): void
    {
        $noCall = new MockHttpClient(function (): MockResponse {
            $this->fail('An empty token must be refused before any request');
        });
        $this->assertFalse((new TurnstileVerifier($noCall, new NullLogger(), '0xSECRET'))->verify('', '1.2.3.4'));

        $down = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $this->assertFalse((new TurnstileVerifier($down, new NullLogger(), '0xSECRET'))->verify('tok', '1.2.3.4'));

        $garbage = new MockHttpClient(new MockResponse('not json', ['http_code' => 500]));
        $this->assertFalse((new TurnstileVerifier($garbage, new NullLogger(), '0xSECRET'))->verify('tok', '1.2.3.4'));
    }
}
