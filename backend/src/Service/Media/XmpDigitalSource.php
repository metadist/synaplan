<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Inserts an IPTC digitalSourceType XMP packet without re-encoding pixels.
 * Used when the content-credentials signer is not running.
 */
final class XmpDigitalSource
{
    public const SOURCE_TYPE = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    public function embed(string $bytes): ?string
    {
        if (str_contains($bytes, 'trainedAlgorithmicMedia') || str_contains($bytes, 'c2pa')) {
            return null;
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $this->png($bytes);
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return $this->jpeg($bytes);
        }

        return null;
    }

    public function packet(): string
    {
        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"'
            .' Iptc4xmpExt:DigitalSourceType="'.self::SOURCE_TYPE.'"/>'
            .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    private function png(string $bytes): ?string
    {
        $end = strpos($bytes, 'IEND');
        if (false === $end || $end < 4) {
            return null;
        }
        $chunkStart = $end - 4;
        $chunk = $this->pngChunk('iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00".$this->packet());

        return substr($bytes, 0, $chunkStart).$chunk.substr($bytes, $chunkStart);
    }

    private function jpeg(string $bytes): string
    {
        $header = "http://ns.adobe.com/xap/1.0/\x00".$this->packet();
        $segment = "\xFF\xE1".pack('n', strlen($header) + 2).$header;

        return substr($bytes, 0, 2).$segment.substr($bytes, 2);
    }

    private function pngChunk(string $type, string $data): string
    {
        $body = $type.$data;
        $crc = pack('N', crc32($body));

        return pack('N', strlen($data)).$body.$crc;
    }
}
