<?php

class UniversalFileHandler
{
    /**
     * Extract text using strategy: Native (when trivial) -> Tika -> Rasterize+Vision fallback.
     * Returns extracted text, quality meta, and strategy used.
     */
    public static function extract(string $relativePath, string $fileTypeExtension): array
    {
        $abs = self::resolveAbsolutePath($relativePath);
        $mime = mime_content_type($abs) ?: '';
        $ext = strtolower($fileTypeExtension);
        $mime = self::ensureOfficeMime($mime, $ext);
        $meta = [
            'mime' => $mime,
            'ext' => $ext
        ];

        // 1) Native trivial types
        if (self::isPlainTextMime($mime)) {
            $text = @file_get_contents($abs) ?: '';
            return [self::clean($text), ['strategy' => 'native_text'] + $meta];
        }

        // If Tika is disabled, support PDFs via vision; others return empty
        if (!ApiKeys::isTikaEnabled()) {
            if (self::isPdfMime($mime) || $ext === 'pdf') {
                $images = Rasterizer::pdfToPng($abs);
                if (!empty($images)) {
                    $text = self::visionAggregate($images);
                    $text = self::clean($text);
                    return [$text, ['strategy' => 'rasterize_vision', 'pages' => count($images)] + $meta];
                }
                return ['', ['strategy' => 'rasterize_vision'] + $meta];
            }
            // Office/unknown without Tika: no extraction
            return ['', ['strategy' => 'tika_disabled'] + $meta];
        }

        // 2) Tika first for documents (office, pdf, html, etc.)
        list($tikaText, $tikaMeta) = TikaClient::extractText($abs, $mime);
        if (is_string($tikaText)) {
            $tikaText = self::clean($tikaText);
            $trim = trim($tikaText);
            $len = mb_strlen($trim);
            // For PDFs, consider low-quality Tika text as unusable
            $isPdf = (self::isPdfMime($mime) || $ext === 'pdf');
            $lowQuality = $isPdf ? self::isLowQuality($trim) : false;
            if ($len > 0 && !$lowQuality) {
                // Prefer Tika whenever it yields usable content
                return [$tikaText, ['strategy' => 'tika'] + $meta + $tikaMeta];
            }
            // If Tika produced empty or low-quality output and this is a PDF, fallback to vision
            if ($isPdf) {
                $images = Rasterizer::pdfToPng($abs);
                if (!empty($images)) {
                    $text = self::visionAggregate($images);
                    $text = self::clean($text);
                    if (mb_strlen(trim($text)) > 0) {
                        return [$text, ['strategy' => 'rasterize_vision', 'pages' => count($images)] + $meta];
                    }
                }
            }
        }

        // If Tika failed or produced unusable output for non-PDF, return empty with strategy marker
        return ['', ['strategy' => 'tika'] + $meta];
    }

    private static function isPlainTextMime(string $mime): bool
    {
        return $mime === 'text/plain' || $mime === 'text/markdown' || $mime === 'text/csv';
    }

    private static function isPdfMime(string $mime): bool
    {
        return $mime === 'application/pdf' || $mime === 'application/x-pdf';
    }

    private static function clean(string $text): string
    {
        return Tools::cleanTextBlock($text);
    }

    private static function resolveAbsolutePath(string $relative): string
    {
        $base = rtrim(UPLOAD_DIR, '/') . '/';
        $candidates = [
            $base . $relative,
            (PROJECT_ROOT . '/public/up/' . $relative),
            (PROJECT_ROOT . '/up/' . $relative)
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        if (!empty($GLOBALS['debug'])) {
            @error_log('UniversalFileHandler: file not found, tried: ' . implode(';', $candidates));
        }
        return $candidates[0];
    }

    private static function isLowQuality(string $text): bool
    {
        $minLen = ApiKeys::getTikaMinLength();
        $minEntropy = ApiKeys::getTikaMinEntropy();
        if (mb_strlen($text) < $minLen) {
            return true;
        }
        $entropy = self::shannonEntropy($text);
        return $entropy < $minEntropy;
    }

    private static function shannonEntropy(string $s): float
    {
        $len = strlen($s);
        if ($len === 0) {
            return 0.0;
        }
        $freq = [];
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $freq[$c] = ($freq[$c] ?? 0) + 1;
        }
        $entropy = 0.0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        return $entropy;
    }

    private static function visionAggregate(array $imageAbsolutePaths): string
    {
        $service = $GLOBALS['AI_PIC2TEXT']['SERVICE'] ?? null;
        if (!$service || !class_exists($service)) {
            return '';
        }
        $fullText = '';
        foreach ($imageAbsolutePaths as $imgAbs) {
            $relPath = self::absoluteToRelativeUp($imgAbs);
            $tmpMsg = [
                'BID' => 0,
                'BUSERID' => $_SESSION['USERPROFILE']['BID'] ?? 0,
                'BFILEPATH' => $relPath,
                'BFILETYPE' => 'png',
                'BFILE' => 1,
                'BTEXT' => ''
            ];
            try {
                $res = $service::explainImage($tmpMsg);
                if (!empty($res['BFILETEXT'])) {
                    if (strlen($fullText) > 0) {
                        $fullText .= "\n\n";
                    }
                    $fullText .= $res['BFILETEXT'];
                }
            } catch (\Throwable $e) {
                @error_log('Vision fallback error: ' . $e->getMessage());
            }
        }
        return $fullText;
    }

    private static function absoluteToRelativeUp(string $abs): string
    {
        $uploadBase = rtrim(UPLOAD_DIR, '/') . '/';
        if (strpos($abs, $uploadBase) === 0) {
            return substr($abs, strlen($uploadBase));
        }
        // Fallbacks for unusual paths
        $projectRoot = dirname(__DIR__, 3);
        $prefix1 = $projectRoot . '/public/up/';
        $prefix2 = $projectRoot . '/up/';
        if (strpos($abs, $prefix1) === 0) {
            return substr($abs, strlen($prefix1));
        }
        if (strpos($abs, $prefix2) === 0) {
            return substr($abs, strlen($prefix2));
        }
        return basename($abs);
    }

    private static function ensureOfficeMime(string $mime, string $ext): string
    {
        // Some environments report XLSX/DOCX/PPTX as application/zip; fix by extension
        $map = [
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'csv'  => 'text/csv'
        ];
        if (isset($map[$ext])) {
            if ($mime === '' || $mime === 'application/zip' || $mime === 'application/octet-stream') {
                return $map[$ext];
            }
        }
        return $mime;
    }
}
