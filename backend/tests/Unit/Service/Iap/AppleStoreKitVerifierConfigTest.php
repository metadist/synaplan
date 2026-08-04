<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iap;

use App\Service\Iap\AppleStoreKitVerifier;
use App\Service\Iap\Exception\IapNotConfiguredException;
use PHPUnit\Framework\TestCase;

/**
 * Guards the configuration checks that run before any Apple data is touched.
 *
 * Both failures below happened on the production cluster: a container recreate
 * wiped the root-certificate directory, and every purchase then failed with an
 * opaque "purchase could not be completed" while the server log said only
 * "not configured". These messages have to name the cause.
 */
final class AppleStoreKitVerifierConfigTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->tempPaths = [];
    }

    public function testReportsAnEmptyRootCertificateDirectoryByPath(): void
    {
        $dir = $this->makeRootCertsDir(withCertificate: false);

        $verifier = new AppleStoreKitVerifier(
            bundleId: 'com.example.app',
            rootCertsDir: $dir,
        );

        $this->expectException(IapNotConfiguredException::class);
        $this->expectExceptionMessage(sprintf('directory "%s" holds no files', $dir));
        $verifier->verifySignedTransaction('irrelevant');
    }

    /**
     * A vanished directory means the bind mount is missing, which is a different
     * repair than an empty one — that distinction is the whole point here.
     */
    public function testDistinguishesAVanishedDirectoryFromAnEmptyOne(): void
    {
        $dir = sys_get_temp_dir().'/apple-roots-never-created';

        $verifier = new AppleStoreKitVerifier(
            bundleId: 'com.example.app',
            rootCertsDir: $dir,
        );

        $this->expectException(IapNotConfiguredException::class);
        $this->expectExceptionMessage(sprintf('directory "%s" does not exist', $dir));
        $verifier->verifySignedTransaction('irrelevant');
    }

    /**
     * An unconfigured server must still be able to construct the verifier and
     * answer. Refusing at construction time turns every /iap/ endpoint into a
     * 500 and defeats the whole not-configured path — which is what a reversed
     * pair of env processors did to CI and to every self-host.
     */
    public function testConstructsWithoutAnAppAppleId(): void
    {
        $verifier = new AppleStoreKitVerifier(
            bundleId: 'com.example.app',
            appAppleId: null,
            rootCertsDir: $this->makeRootCertsDir(withCertificate: false),
        );

        $this->expectException(IapNotConfiguredException::class);
        $verifier->verifySignedTransaction('irrelevant');
    }

    public function testReportsAMissingBundleId(): void
    {
        $verifier = new AppleStoreKitVerifier(rootCertsDir: $this->makeRootCertsDir());

        $this->expectException(IapNotConfiguredException::class);
        $this->expectExceptionMessage('IAP_APPLE_BUNDLE_ID is empty');
        $verifier->verifySignedTransaction('irrelevant');
    }

    public function testRefusesAnUnknownEnvironmentInsteadOfFallingBackToProduction(): void
    {
        $verifier = new AppleStoreKitVerifier(
            bundleId: 'com.example.app',
            environment: 'staging',
            rootCertsDir: $this->makeRootCertsDir(),
        );

        $this->expectException(IapNotConfiguredException::class);
        $this->expectExceptionMessage('expected one of Sandbox, Production');
        $verifier->verifySignedTransaction('irrelevant');
    }

    public function testAcceptsTheEnvironmentRegardlessOfCasing(): void
    {
        $verifier = new AppleStoreKitVerifier(
            bundleId: 'com.example.app',
            environment: 'sandbox',
            rootCertsDir: $this->makeRootCertsDir(),
        );

        // The environment passes, so the failure comes from the placeholder
        // certificate the verifier is then handed — not from the spelling.
        $this->expectException(IapNotConfiguredException::class);
        $this->expectExceptionMessage('could not be initialized');
        $verifier->verifySignedTransaction('irrelevant');
    }

    /**
     * A root-certificate directory; the contents only have to be non-empty for
     * the "is anything there at all" check this class performs.
     */
    private function makeRootCertsDir(bool $withCertificate = true): string
    {
        $dir = sys_get_temp_dir().'/apple-roots-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempPaths[] = $dir;

        if ($withCertificate) {
            $path = $dir.'/placeholder.cer';
            file_put_contents($path, 'not a real certificate');
            // Removed before the directory (tearDown unwinds in order).
            array_splice($this->tempPaths, -1, 0, [$path]);
        }

        return $dir;
    }
}
