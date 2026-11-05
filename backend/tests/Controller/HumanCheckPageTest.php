<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HumanCheckPageTest extends WebTestCase
{
    public function testPageIsPublicAndFrameableOnlyByTheApp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/auth/human-check?parent=capacitor://localhost&action=login&lang=de&theme=light&x=<script>alert(1)</script>');

        $this->assertResponseIsSuccessful();
        $response = $client->getResponse();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-Frame-Options'));

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-ancestors capacitor://localhost ionic://localhost https://localhost', $policy);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $policy);
        $this->assertMatchesRegularExpression("/script-src 'nonce-[a-f0-9]{32}'/", $policy);

        $this->assertStringContainsString('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', $html);
        $this->assertStringContainsString('/api/v1/config/runtime', $html);
        $this->assertStringContainsString('synaplan-human-check', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('__NONCE__', $html);
        $this->assertMatchesRegularExpression('/<script nonce="[a-f0-9]{32}">/', $html);
    }
}
