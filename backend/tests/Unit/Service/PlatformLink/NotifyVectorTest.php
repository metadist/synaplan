<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\Federation\FederationException;
use App\Service\Federation\FederationSigner;
use PHPUnit\Framework\TestCase;

/**
 * The same file is checked by every cloud app, so a canonical-JSON difference
 * fails here instead of in production.
 */
final class NotifyVectorTest extends TestCase
{
    public function testTheSharedVectorsMatchTheSigner(): void
    {
        $path = dirname(__DIR__, 3).'/Fixtures/cloud-apps-contract/notify-vectors.json';
        $doc = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($doc);
        $signer = new FederationSigner();

        foreach ($doc['vectors'] as $vector) {
            self::assertSame($vector['expect'], $this->verdict($signer, $doc, $vector['body']), $vector['name']);
        }
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $body
     */
    private function verdict(FederationSigner $signer, array $doc, array $body): string
    {
        try {
            $signer->verifyBody($body, (string) $doc['public_key']);
        } catch (FederationException) {
            return 'bad_signature';
        }
        if (($body['instance_id'] ?? null) !== $doc['receiver_instance_id']) {
            return 'wrong_instance';
        }
        $ts = (int) ($body['ts'] ?? 0);
        if (abs((int) $doc['now'] - $ts) > (int) $doc['window_seconds']) {
            return 'stale';
        }

        return 'ok';
    }
}
