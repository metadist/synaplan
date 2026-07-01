<?php

declare(strict_types=1);

namespace App\Tests\Service\File;

/**
 * Shared helpers that keep HEIC tests deterministic across environments.
 *
 * HEIC encode/decode depends on the ambient ImageMagick build and its libheif
 * delegate, which differs between local Docker (full libheif) and the CI runner
 * (imagick lists HEIC as a format but cannot actually round-trip it). These
 * helpers generate a sample and skip — rather than fail — when the environment
 * cannot genuinely encode/decode HEIC, so the tests only assert where the
 * capability really exists.
 */
trait HeicTestSupportTrait
{
    /**
     * Produce a tiny HEIC sample, or skip the test if this environment cannot
     * encode HEIC at all.
     */
    private function createHeicSampleOrSkip(int $width = 32, int $height = 32): string
    {
        if (!extension_loaded('imagick') || !in_array('HEIC', \Imagick::queryFormats('HEIC'), true)) {
            $this->markTestSkipped('imagick with HEIC support is required for this test');
        }

        try {
            $imagick = new \Imagick();
            $imagick->newImage($width, $height, new \ImagickPixel('blue'));
            $imagick->setImageFormat('heic');
            $bytes = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $e) {
            $this->markTestSkipped('imagick cannot encode a HEIC sample here: '.$e->getMessage());
        }

        if ('' === $bytes) {
            $this->markTestSkipped('imagick produced an empty HEIC sample');
        }

        return $bytes;
    }

    /**
     * Skip the test unless the environment can actually decode the given HEIC
     * bytes (independently of the code under test). This distinguishes a real
     * converter bug (env decodes, converter still fails) from a missing delegate
     * (env cannot decode at all → skip).
     */
    private function skipUnlessEnvironmentDecodesHeic(string $heicBytes): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'heicprobe_').'.heic';
        file_put_contents($temp, $heicBytes);

        try {
            $imagick = new \Imagick();
            $imagick->readImage($temp);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $e) {
            $this->markTestSkipped('environment cannot decode HEIC (libheif delegate unavailable): '.$e->getMessage());
        } finally {
            @unlink($temp);
        }
    }
}
