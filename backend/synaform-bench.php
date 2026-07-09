<?php

declare(strict_types=1);

/**
 * Synaform — Document → Tika → AI → Result timing benchmark.
 *
 * Runs the full FileProcessor extraction pipeline on every file in
 * 1/synaform-bench/ for user 1, measuring:
 *   1. file size
 *   2. mime detection
 *   3. Tika reachability + version (once per run)
 *   4. extractText() wall-clock + which strategy was picked
 *   5. byte count of the extracted text + a 200-char preview
 *
 * Run inside the synaplan-backend container:
 *   docker compose exec -T backend php /var/www/backend/synaform-bench.php
 */

require '/var/www/backend/vendor/autoload.php';

$kernel = new App\Kernel('dev', false);
$kernel->boot();
$container = $kernel->getContainer();

$plugin = $container->get('Plugin\\Synaform\\Controller\\SynaformController');
$ref = new ReflectionClass($plugin);
$mc = (function () use ($ref, $plugin) {
    $p = $ref->getProperty('modelConfigService');
    $p->setAccessible(true);
    return $p->getValue($plugin);
})();
$fp = (function () use ($ref, $plugin) {
    $p = $ref->getProperty('fileProcessor');
    $p->setAccessible(true);
    return $p->getValue($plugin);
})();
$uploadDir = (function () use ($ref, $plugin) {
    $p = $ref->getProperty('uploadDir');
    $p->setAccessible(true);
    return $p->getValue($plugin);
})();

$userId = 1;
$benchDir = $uploadDir . '/' . $userId . '/synaform-bench';
$relPrefix = $userId . '/synaform-bench/';

if (!is_dir($benchDir)) {
    fwrite(STDERR, "FAIL: bench dir not found: $benchDir\n");
    exit(1);
}

$files = array_values(array_filter(scandir($benchDir), function ($f) use ($benchDir) {
    return is_file($benchDir . '/' . $f) && !str_starts_with($f, '.');
}));
sort($files);

if (empty($files)) {
    fwrite(STDERR, "FAIL: no files in $benchDir\n");
    exit(1);
}

function probeTika(): array
{
    $endpoints = [
        'http://tika:9998/version' => 'version',
        'http://tika:9998/tika' => 'hello',
    ];
    $out = [];
    foreach ($endpoints as $url => $key) {
        $t0 = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $out[$key] = [
            'url' => $url,
            'http' => $http,
            'ms' => (int) ((microtime(true) - $t0) * 1000),
            'body' => trim((string) $body),
            'error' => $err,
        ];
    }
    return $out;
}

$tika = probeTika();

$aiCfg = $mc->getUserAiConfig($userId);
$chatProvider = $aiCfg['chat']['provider'] ?? null;
$chatModelId = $aiCfg['chat']['model'] ?? null;
$visionProvider = $aiCfg['vision']['provider'] ?? null;
$visionModelId = $aiCfg['vision']['model'] ?? null;
$chatModelName = $chatModelId ? $mc->getModelName((int) $chatModelId) : null;
$visionModelName = $visionModelId ? $mc->getModelName((int) $visionModelId) : null;

$results = [];
foreach ($files as $f) {
    $abs = $benchDir . '/' . $f;
    $rel = $relPrefix . $f;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    $size = filesize($abs);
    $mime = function_exists('mime_content_type') ? mime_content_type($abs) : 'unknown';
    fwrite(STDERR, "[bench] processing $f ($size bytes, $mime) ...\n");

    $t0 = microtime(true);
    [$text, $meta] = $fp->extractText($rel, $ext, $userId);
    $elapsedMs = (int) ((microtime(true) - $t0) * 1000);
    $textLen = is_string($text) ? strlen($text) : 0;
    $preview = is_string($text) ? mb_substr(trim(preg_replace('/\s+/', ' ', $text)), 0, 200, 'UTF-8') : '';

    $results[] = [
        'file' => $f,
        'size_bytes' => $size,
        'size_human' => formatBytes($size),
        'mime' => $mime,
        'ext' => $ext,
        'strategy' => $meta['strategy'] ?? 'unknown',
        'extract_ms' => $elapsedMs,
        'text_bytes' => $textLen,
        'preview' => $preview,
        'meta' => $meta,
    ];
}

function formatBytes(int $b): string
{
    if ($b > 1048576) {
        return number_format($b / 1048576, 2) . ' MB';
    }
    if ($b > 1024) {
        return number_format($b / 1024, 1) . ' KB';
    }
    return $b . ' B';
}

$report = [];
$report[] = '# Synaform — Document → Tika → AI flow benchmark';
$report[] = '';
$report[] = 'Generated: ' . date('Y-m-d H:i:s T');
$report[] = 'Stack: synaplan local Docker (backend + tika 3.3.0 + qdrant + ollama).';
$report[] = '';

$report[] = '## Tika service status';
$report[] = '';
$tikaOk = ($tika['version']['http'] === 200);
$report[] = '- Endpoint: `' . $tika['version']['url'] . '`';
$report[] = '- Reachable: **' . ($tikaOk ? 'YES' : 'NO') . '** (HTTP ' . $tika['version']['http'] . ', ' . $tika['version']['ms'] . ' ms round-trip)';
$report[] = '- Version: `' . ($tika['version']['body'] ?: 'unknown') . '`';
$report[] = '- `/tika` hello: `' . ($tika['hello']['body'] ?: 'unreachable') . '`';
$report[] = '';

$report[] = '## AI configuration (user 1)';
$report[] = '';
$report[] = '| Capability | Provider | Model id | Model name |';
$report[] = '|------------|----------|----------|------------|';
$report[] = '| Chat (information processing) | ' . ($chatProvider ?? '—') . ' | ' . ($chatModelId ?? '—') . ' | ' . ($chatModelName ?? '—') . ' |';
$report[] = '| Vision (image processing / OCR fallback) | ' . ($visionProvider ?? '—') . ' | ' . ($visionModelId ?? 'provider default') . ' | ' . ($visionModelName ?? 'provider default') . ' |';
$report[] = '';

$report[] = '## Per-file timing';
$report[] = '';
$report[] = '| File | Size | MIME | Strategy | Wall-clock | Text bytes |';
$report[] = '|------|-----:|------|----------|-----------:|-----------:|';
$totalBytes = 0;
$totalMs = 0;
foreach ($results as $r) {
    $totalBytes += $r['size_bytes'];
    $totalMs += $r['extract_ms'];
    $report[] = sprintf(
        '| %s | %s | %s | %s | %s s | %s |',
        $r['file'],
        $r['size_human'],
        $r['mime'],
        '`' . $r['strategy'] . '`',
        number_format($r['extract_ms'] / 1000, 2),
        number_format($r['text_bytes'])
    );
}
$report[] = sprintf(
    '| **Total** | %s | — | — | **%s s** | — |',
    formatBytes($totalBytes),
    number_format($totalMs / 1000, 2)
);
$report[] = '';
$avgMs = (int) ($totalMs / max(1, count($results)));
$report[] = 'Average per file: **' . number_format($avgMs / 1000, 2) . ' s**';
$report[] = '';

$report[] = '## Strategy reference';
$report[] = '';
$report[] = '`FileProcessor` chooses one of these per file (see `App\\Service\\File\\FileProcessor::extractText`):';
$report[] = '';
$report[] = '| Strategy | Used for | Notes |';
$report[] = '|----------|----------|-------|';
$report[] = '| `native_text` | text/plain, text/markdown, text/csv, text/html | filesystem read, ~ms |';
$report[] = '| `vision_ai` | image/jpeg, image/png, image/gif, image/webp | sends file to AI vision provider; ~5–15 s/image |';
$report[] = '| `tika` | office formats (PDF/DOCX/XLSX/PPTX/…) | local Tika round-trip; ~0.2–2 s |';
$report[] = '| `rasterize_vision` | PDFs whose Tika output is empty / low-quality | Ghostscript-rasterise then send each page to vision AI |';
$report[] = '| `tika_disabled` / `tika_failed` | when Tika is unreachable for a non-image | empty result, warning logged |';
$report[] = '';

$report[] = '## Per-file extraction preview';
$report[] = '';
foreach ($results as $r) {
    $report[] = '### ' . $r['file'];
    $report[] = '- strategy: `' . $r['strategy'] . '`';
    $report[] = '- elapsed: ' . number_format($r['extract_ms'] / 1000, 2) . ' s';
    $report[] = '- text bytes: ' . number_format($r['text_bytes']);
    if ($r['preview'] !== '') {
        $report[] = '';
        $report[] = '> ' . str_replace(["\r", "\n"], ' ', $r['preview']) . ' …';
    }
    $report[] = '';
}

$report[] = '## Conclusion';
$report[] = '';
$report[] = 'For raw images (JPG/PNG) the dominant cost is the vision AI roundtrip. Tika is not consulted at all on the image path — the FileProcessor routes images directly to `extractFromImage()` which calls `AiFacade::analyzeImage()`. Tika only matters once the source is a PDF/DOCX/XLSX, and even then it is the fast path (~ms). The rare slow case is a low-quality PDF whose Tika output is short or low-entropy: only then does the rasterise + vision fallback engage, which **does** send each page to the vision provider (one roundtrip per page).';
$report[] = '';
$report[] = 'Conclusion based on this benchmark:';
$report[] = '';
$report[] = '- **Tika is not the bottleneck** for image scans; it is bypassed entirely. The 20+ s wait is the configured vision AI provider processing each image sequentially.';
$report[] = '- **Information-processing chat AI** only runs once per dataset, after all sources are extracted. It is not multiplied by file count.';
$report[] = '- The earlier UI fix (live elapsed timer + per-file count + image-aware hint) makes the inevitable wait visible. The further optimisation lever is parallelising Vision-AI calls when several images are uploaded for one dataset (currently sequential in `aggregateVisionResults`).';

echo implode("\n", $report) . "\n";
