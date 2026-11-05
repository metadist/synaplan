<?php

declare(strict_types=1);

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\File\FileTypeResolver;

/**
 * Whether a turn that carries a picture is a look-change (PIC2PIC), pixel-exact
 * file work (stamp / script), or too close to call — in which case the run
 * asks before it picks `code_run`.
 */
final readonly class ImageEditIntent
{
    public function kind(Message $message, string $text): ImageEditKind
    {
        if (!$this->hasImageAttachment($message)) {
            return ImageEditKind::None;
        }

        if ($this->isProgrammatic($text)) {
            return ImageEditKind::Programmatic;
        }

        if ($this->isAesthetic($text)) {
            return ImageEditKind::Aesthetic;
        }

        if ($this->isAmbiguousSizeOp($text)) {
            return ImageEditKind::Ambiguous;
        }

        return ImageEditKind::None;
    }

    private function isProgrammatic(string $text): bool
    {
        if (1 === preg_match('/\b(stempel\w*|wasserzeichen|watermark|stamp|filigrane|filigran|sello|marca de agua|damga)\b/iu', $text)) {
            return true;
        }

        $namesRuntime = 1 === preg_match('/\b(python|node\.?js|node|javascript|script|code|program)\b/i', $text);
        if (!$namesRuntime) {
            return false;
        }

        return 1 === preg_match('/\b(run|execute|compute|apply|process)\b/i', $text)
            || 1 === preg_match('/\b(führe|ausführen|ausfuehren|anwenden)\b/iu', $text);
    }

    private function isAesthetic(string $text): bool
    {
        return 1 === preg_match(
            '/(?:'
            .'\b(mittig|zentrier\w*|schöner|schoener|flach|hintergrund|komposition|platzier\w*|verändern|verändere|ändere|aendere)\b'
            .'|'
            .'\b(center|centre|flatten|background|compose|composition|layout|restyle|look)\b'
            .'|'
            .'\b(make it look|(?:change|edit) (?:this |the )?(?:image|picture|photo))\b'
            .'|'
            .'\b(centrar|centra|fondo|composición|composicion|estilo)\b'
            .'|'
            .'\b(centrer|aplati|arrière-plan|arriere-plan|composition|apparence)\b'
            .'|'
            .'\b(ortala|arka plan|görünüm|gorunum)\b'
            .')/iu',
            $text,
        );
    }

    private function isAmbiguousSizeOp(string $text): bool
    {
        return 1 === preg_match(
            '/(?:'
            .'\b(crop|resize|rotate|scale|skalier\w*|zuschneid\w*|breiter|wider|recorta|recadre|kırp)\b'
            .'|'
            .'\b(\d+\s*x\s*\d+|\d+\s*px|pixels?|pixelgenau)\b'
            .'|'
            .'\b(halb so groß|half (?:the )?size|to half)\b'
            .')/iu',
            $text,
        );
    }

    private function hasImageAttachment(Message $message): bool
    {
        foreach ($message->getFiles() as $file) {
            if ('image' === FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName())) {
                return true;
            }
        }

        if ($message->getFile() > 0) {
            return 'image' === FileTypeResolver::resolveCategory(
                $message->getFileType() ?: '',
                '',
                (string) $message->getFilePath(),
            );
        }

        return false;
    }
}
