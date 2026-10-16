<?php

declare(strict_types=1);

namespace App\AI\Image;

/**
 * Rewrites Chat Completions image parts for upstreams that accept only inline
 * base64 PNG or JPEG (Cerebras).
 *
 * GIF, WebP and other raster data URLs are transcoded to PNG (first frame, so
 * transparency survives). External image URLs are rejected instead of fetched:
 * downloading a caller-supplied URL server-side would be an SSRF vector. The
 * `detail` field is dropped because these upstreams reject it.
 */
final class PngJpegInlineImages
{
    /** @var list<string> */
    private const ACCEPTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/jpg'];

    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     *
     * @throws UnsupportedImageInputException
     */
    public static function normalizeMessages(array $messages, string $providerLabel): array
    {
        foreach ($messages as $index => $message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $partIndex => $part) {
                if (!is_array($part) || 'image_url' !== ($part['type'] ?? null)) {
                    continue;
                }

                $imageUrl = $part['image_url'] ?? null;
                $url = is_array($imageUrl) ? ($imageUrl['url'] ?? null) : $imageUrl;
                if (!is_string($url) || '' === $url) {
                    continue;
                }

                $content[$partIndex]['image_url'] = ['url' => self::toDataUrl($url, $providerLabel)];
            }

            $messages[$index]['content'] = $content;
        }

        return $messages;
    }

    /**
     * @throws UnsupportedImageInputException
     */
    public static function toDataUrl(string $url, string $providerLabel): string
    {
        if (!str_starts_with($url, 'data:')) {
            throw new UnsupportedImageInputException(sprintf('%s accepts images only as uploaded PNG or JPEG files, not as links. Upload the image instead of linking to it.', $providerLabel));
        }

        if (1 !== preg_match('#^data:([^;,]+);base64,(.*)$#s', $url, $matches)) {
            throw new UnsupportedImageInputException(sprintf('%s could not read this image. Upload it again as a PNG or JPEG file.', $providerLabel));
        }

        $mimeType = strtolower(trim($matches[1]));
        if (in_array($mimeType, self::ACCEPTED_MIME_TYPES, true)) {
            return $url;
        }

        $bytes = base64_decode($matches[2], true);
        $png = false === $bytes ? null : self::transcodeToPng($bytes);
        if (null === $png) {
            throw new UnsupportedImageInputException(sprintf('%s accepts only PNG or JPEG images, and this %s image could not be converted. Save it as PNG or JPEG and upload it again.', $providerLabel, $mimeType));
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private static function transcodeToPng(string $bytes): ?string
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return null;
        }

        try {
            $source = new \Imagick();
            $source->readImageBlob($bytes);
            $source->setIteratorIndex(0);
            $frame = $source->getImage();
            $frame->setImageFormat('png');
            $frame->stripImage();
            $png = $frame->getImageBlob();
            $frame->clear();
            $source->clear();

            return '' !== $png ? $png : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
