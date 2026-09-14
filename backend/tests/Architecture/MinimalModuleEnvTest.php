<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\Gate\ModuleGateConfig;
use App\Service\Feature\FeatureFlagEnv;
use PHPUnit\Framework\TestCase;

/**
 * Intermezzo S4 — the minimal overlay empties every decisive module env.
 *
 * A new module that can become "configured" from the environment must appear
 * in both `backend/.env.minimal` and `docker-compose.minimal.yml`. Otherwise
 * the CI lane would report `app:modules:list --assert-none-configured` green
 * while an E2E stack still had the module on, or the other way around.
 */
final class MinimalModuleEnvTest extends TestCase
{
    /**
     * Env keys that flip `isConfigured()` for the v1 module set.
     * Timeouts, quality thresholds and bools that cannot alone configure a
     * module (STRIPE_AUTOMATIC_TAX, WHATSAPP_GRAPH_API_BASE_URL) stay out.
     *
     * @var array<string, list<string>>
     */
    private const DECISIVE_ENV = [
        'tika' => ['TIKA_BASE_URL', 'TIKA_URL'],
        'docling' => ['DOCLING_BASE_URL'],
        'office_convert' => ['OFFICE_CONVERT_URL'],
        'searxng' => ['SEARXNG_BASE_URL'],
        'piper_tts' => ['SYNAPLAN_TTS_URL'],
        'local_ai' => ['OLLAMA_BASE_URL'],
        'higgsfield' => ['HIGGSFIELD_API_KEY', 'HIGGSFIELD_API_SECRET'],
        'google_ai' => ['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'],
        'thehive' => ['THEHIVE_API_KEY'],
        'stripe_billing' => ['STRIPE_SECRET_KEY', 'STRIPE_PRICE_PRO', 'STRIPE_WEBHOOK_SECRET'],
        'mobile_iap' => ['IAP_PRODUCT_PRO', 'IAP_PRODUCT_TEAM', 'IAP_PRODUCT_BUSINESS'],
        'whatsapp' => ['WHATSAPP_ENABLED', 'WHATSAPP_ACCESS_TOKEN'],
        'compute' => ['COMPUTE_URL', 'COMPUTE_TOKEN'],
    ];

    public function testEveryDeclaredModuleHasADecisiveEnvList(): void
    {
        $fromSrc = $this->moduleIdsFromSrc();
        $listed = array_keys(self::DECISIVE_ENV);
        sort($fromSrc);
        sort($listed);
        $this->assertSame($fromSrc, $listed, 'Add the new module to DECISIVE_ENV and to backend/.env.minimal + docker-compose.minimal.yml');
    }

    public function testEnvMinimalListsEveryDecisiveAndGateKey(): void
    {
        $keys = $this->keysInEnvFile($this->backendRoot().'/.env.minimal');
        foreach ($this->requiredKeys() as $key) {
            $this->assertContains($key, $keys, "backend/.env.minimal is missing {$key}");
        }
    }

    public function testComposeOverlayListsEveryDecisiveAndGateKey(): void
    {
        $path = $this->composeOverlayPath();
        if (null === $path) {
            self::markTestSkipped('docker-compose.minimal.yml is not mounted in this environment (covered by tests/minimal-module-env.test.mjs)');
        }
        $keys = $this->keysInComposeOverlay($path);
        foreach ($this->requiredKeys() as $key) {
            $this->assertContains($key, $keys, "docker-compose.minimal.yml is missing {$key}");
        }
    }

    /**
     * @return list<string>
     */
    private function requiredKeys(): array
    {
        $decisive = array_merge(...array_values(self::DECISIVE_ENV));
        $gates = [];
        foreach (array_keys(self::DECISIVE_ENV) as $id) {
            $gates[] = FeatureFlagEnv::envVarFor(ModuleGateConfig::GROUP, ModuleGateConfig::settingFor($id));
        }

        return array_values(array_unique([...$decisive, ...$gates]));
    }

    /**
     * @return list<string>
     */
    private function moduleIdsFromSrc(): array
    {
        $ids = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backendRoot().'/src/Module', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $this->assertNotFalse($src);
            if (preg_match("/public const ID = '([a-z][a-z0-9_]*)';/", $src, $match)) {
                $ids[] = $match[1];
            }
        }
        $ids = array_values(array_unique($ids));
        $this->assertNotEmpty($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function keysInEnvFile(string $path): array
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Cannot read {$path}");
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $raw, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function keysInComposeOverlay(string $path): array
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Cannot read {$path}");
        preg_match_all('/^\s+([A-Z][A-Z0-9_]*):/m', $raw, $matches);

        return $matches[1];
    }

    private function backendRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function composeOverlayPath(): ?string
    {
        $path = dirname($this->backendRoot()).'/docker-compose.minimal.yml';

        return is_file($path) ? $path : null;
    }
}
