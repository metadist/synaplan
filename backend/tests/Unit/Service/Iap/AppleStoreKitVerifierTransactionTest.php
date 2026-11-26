<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iap;

use App\Service\Iap\AppleStoreKitVerifier;
use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;
use App\Service\Iap\IapEntitlementState;
use PHPUnit\Framework\TestCase;

/**
 * Covers what this class checks on top of the Apple library: that a
 * transaction belongs to this app, and that the library's unsigned
 * environments stay out of a production kernel.
 *
 * The transactions here are unsigned on purpose. The library accepts them in
 * its `LocalTesting` environment, which is exactly the mode the second half
 * of these tests locks away.
 */
final class AppleStoreKitVerifierTransactionTest extends TestCase
{
    private const BUNDLE_ID = 'com.example.app';

    private string $rootCertsDir = '';

    protected function setUp(): void
    {
        $this->rootCertsDir = sys_get_temp_dir().'/apple-roots-'.bin2hex(random_bytes(6));
        mkdir($this->rootCertsDir);
        file_put_contents($this->rootCertsDir.'/root.cer', $this->selfSignedCertificateDer());
    }

    protected function tearDown(): void
    {
        unlink($this->rootCertsDir.'/root.cer');
        rmdir($this->rootCertsDir);
    }

    public function testAcceptsATransactionOfThisApp(): void
    {
        $signedAt = time() - 30;
        $entitlement = $this->verifier('LocalTesting', 'test')->verifySignedTransaction(
            $this->unsignedTransaction(self::BUNDLE_ID, $signedAt),
        );

        self::assertSame('app.pro', $entitlement->productId);
        self::assertSame('orig-1', $entitlement->purchaseId);
        self::assertSame(IapEntitlementState::ACTIVE, $entitlement->state);
        self::assertSame($signedAt, $entitlement->signedAt);
    }

    /**
     * Apple signs the transactions of every app with the same chain, and a
     * product id is unique only within one app.
     */
    public function testRejectsATransactionOfAnotherApp(): void
    {
        $verifier = $this->verifier('LocalTesting', 'test');

        $this->expectException(IapVerificationException::class);
        $this->expectExceptionMessage('bundle id mismatch');
        $verifier->verifySignedTransaction($this->unsignedTransaction('com.attacker.app', time()));
    }

    public function testRejectsATransactionWithoutABundleId(): void
    {
        $verifier = $this->verifier('LocalTesting', 'test');

        $this->expectException(IapVerificationException::class);
        $verifier->verifySignedTransaction($this->unsignedTransaction(null, time()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsignedEnvironmentsOutsideDevelopment(): iterable
    {
        yield 'Xcode in prod' => ['Xcode', 'prod'];
        yield 'xcode, any casing' => ['xcode', 'prod'];
        yield 'LocalTesting in prod' => ['LocalTesting', 'prod'];
        yield 'Xcode in an unknown kernel environment' => ['Xcode', 'staging'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsignedEnvironmentsOutsideDevelopment')]
    public function testRefusesUnsignedEnvironmentsOutsideDevelopment(string $appleEnvironment, string $appEnv): void
    {
        $verifier = $this->verifier($appleEnvironment, $appEnv);

        self::assertFalse($verifier->isConfigured());

        try {
            $verifier->verifySignedTransaction($this->unsignedTransaction(self::BUNDLE_ID, time(), $appleEnvironment));
            self::fail('Expected IapNotConfiguredException');
        } catch (IapNotConfiguredException $e) {
            self::assertStringContainsString('IAP_APPLE_ENVIRONMENT', $e->getMessage());
            self::assertStringContainsString('unsigned', $e->getMessage());
        }
    }

    public function testUnsignedNotificationsAreRefusedOutsideDevelopment(): void
    {
        $verifier = $this->verifier('Xcode', 'prod');

        $this->expectException(IapNotConfiguredException::class);
        $verifier->verifyNotification($this->unsignedTransaction(self::BUNDLE_ID, time(), 'Xcode'));
    }

    public function testAllowsXcodeOnADevelopmentKernel(): void
    {
        $verifier = $this->verifier('Xcode', 'dev');

        self::assertTrue($verifier->isConfigured());
        self::assertSame(
            'orig-1',
            $verifier->verifySignedTransaction($this->unsignedTransaction(self::BUNDLE_ID, time(), 'Xcode'))->purchaseId,
        );
    }

    private function verifier(string $appleEnvironment, string $appEnv): AppleStoreKitVerifier
    {
        return new AppleStoreKitVerifier(
            bundleId: self::BUNDLE_ID,
            environment: $appleEnvironment,
            rootCertsDir: $this->rootCertsDir,
            appEnv: $appEnv,
        );
    }

    private function unsignedTransaction(?string $bundleId, int $signedAt, string $environment = 'LocalTesting'): string
    {
        $payload = [
            'transactionId' => 'tx-1',
            'originalTransactionId' => 'orig-1',
            'productId' => 'app.pro',
            'environment' => 0 === strcasecmp($environment, 'xcode') ? 'Xcode' : $environment,
            'signedDate' => $signedAt * 1000,
            'expiresDate' => (time() + 3600) * 1000,
        ];
        if (null !== $bundleId) {
            $payload['bundleId'] = $bundleId;
        }

        return implode('.', [
            $this->base64Url(json_encode(['alg' => 'ES256'], JSON_THROW_ON_ERROR)),
            $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR)),
            $this->base64Url('unsigned'),
        ]);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * The library parses every root certificate when it is constructed, so
     * the directory needs one well-formed certificate. Nothing is verified
     * against it in the unsigned environments.
     */
    private function selfSignedCertificateDer(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'Test Root'], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($request);
        self::assertNotTrue($request);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));

        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        self::assertNotFalse($der);

        return $der;
    }
}
