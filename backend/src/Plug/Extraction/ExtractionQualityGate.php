<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

use App\Plug\PlugConfigService;
use App\Service\File\TextCleaner;

/**
 * Generalizes FileProcessor's PDF {@see TextCleaner::isLowQuality()} check.
 * Families in EXTRACTION.QUALITY.apply_to (seeded `pdf`) must meet
 * min_length / min_entropy; a markdown result with a table or heading
 * still passes (table-heavy pages are legitimately repetitive).
 */
final readonly class ExtractionQualityGate
{
    public function __construct(
        private PlugConfigService $plugConfig,
        private TextCleaner $textCleaner,
    ) {
    }

    public function verdict(ExtractionResult $result, ExtractionRequest $request): GateVerdict
    {
        if ('' === trim($result->text) && (null === $result->markdown || '' === trim($result->markdown))) {
            return GateVerdict::fail('empty');
        }

        if (!$this->appliesTo($request)) {
            return GateVerdict::pass('not_applicable');
        }

        $text = '' !== trim($result->text) ? $result->text : (string) $result->markdown;
        $lowQuality = $this->textCleaner->isLowQuality(
            $text,
            $this->plugConfig->qualityMinLength(),
            $this->plugConfig->qualityMinEntropy(),
        );
        if (!$lowQuality) {
            return GateVerdict::pass('quality_ok');
        }

        $markdown = $result->markdown ?? '';
        if ('' !== $markdown && $this->hasTableOrHeading($markdown)) {
            return GateVerdict::pass('markdown_structure');
        }

        return GateVerdict::fail('low_quality');
    }

    private function appliesTo(ExtractionRequest $request): bool
    {
        $ext = strtolower($request->ext);
        $mime = strtolower($request->mime);
        foreach ($this->plugConfig->qualityApplyTo() as $token) {
            if ($token === $ext || $token === $request->family) {
                return true;
            }
            if ('pdf' === $token && ('pdf' === $ext || str_contains($mime, 'pdf'))) {
                return true;
            }
        }

        return false;
    }

    private function hasTableOrHeading(string $markdown): bool
    {
        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            $trim = ltrim($line);
            if (str_starts_with($trim, '|')) {
                return true;
            }
            if (1 === preg_match('/^#{1,6}\s+\S/', $trim)) {
                return true;
            }
        }

        return false;
    }
}
