<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * Locates and decodes the officemaker file-generation envelope
 * ({@code {"BFILEPATH":"…","BFILETEXT":"…"}}) inside a raw model reply.
 *
 * The model is instructed to answer with pure JSON, but in practice it
 * sometimes prepends a conversational sentence ("Here is your presentation:")
 * or wraps the object in a markdown code fence. The historic extractors only
 * accepted a reply that *starts* with `{`, so a prose preamble skipped file
 * generation entirely and the raw `{"BFILEPATH",…}` blob leaked into the chat
 * (#1406). This helper salvages the embedded object in all three shapes:
 *
 *  - pure JSON: {@code {"BFILEPATH":"a.docx","BFILETEXT":"…"}}
 *  - fenced JSON: ```json\n{ … }\n```
 *  - prose + JSON: {@code Here is your file: {"BFILEPATH":"a.docx",…}}
 *
 * Pure string parsing, no dependencies — shared by the streaming and
 * non-streaming generation paths so they cannot drift apart.
 */
final class FileGenerationEnvelope
{
    private function __construct()
    {
    }

    /**
     * @return array{filename: string, content: string, extension: string, export?: string}|null
     */
    public static function extract(string $content): ?array
    {
        foreach (self::candidateObjects($content) as $candidate) {
            $data = self::decode($candidate);
            if (null !== $data) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Whether a reply still carries the file-envelope signature even though it
     * could not be decoded. Callers use this to suppress malformed raw payloads
     * instead of exposing document content to the user.
     */
    public static function hasSignature(string $content): bool
    {
        return str_contains($content, '"BFILEPATH"')
            && str_contains($content, '"BFILETEXT"');
    }

    /**
     * Office format the user explicitly named in the request ("als Excel",
     * "as a Word document", "in PowerPoint"), as a canonical extension — or
     * null when no format was named.
     *
     * Last match wins ("mach aus dem CSV ein Excel" → xlsx). Deliberately
     * conservative: bare "word" is excluded ("in other words"), and PDF is
     * excluded (the BEXPORT/pdf-engine logic owns that path, not this one).
     */
    public static function requestedFormat(string $text): ?string
    {
        $patterns = [
            'xlsx' => '/\b\.?xlsx?\b|\bexcel\b|\bspreadsheet\b/i',
            'docx' => '/\b\.?docx?\b|word-dokument|word-datei|microsoft word|\bals word\b|\bas word\b|\bword document\b/i',
            'pptx' => '/\b\.?pptx?\b|powerpoint|präsentation|presentation/i',
            'csv' => '/\b\.?csv\b/i',
        ];

        $winner = null;
        $winnerPos = -1;
        foreach ($patterns as $extension => $pattern) {
            if (1 !== preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$match, $pos]) {
                if ($pos >= $winnerPos) {
                    $winner = $extension;
                    $winnerPos = $pos;
                }
            }
        }

        return $winner;
    }

    /**
     * Candidate JSON object strings to attempt, in order of confidence.
     *
     * @return list<string>
     */
    private static function candidateObjects(string $content): array
    {
        $candidates = [];

        $trimmed = trim($content);
        if (str_starts_with($trimmed, '{')) {
            $candidates[] = $trimmed;
        }

        if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $content, $matches)) {
            $fenced = trim($matches[1]);
            if (str_starts_with($fenced, '{')) {
                $candidates[] = $fenced;
            }
        }

        $embedded = self::embeddedObject($content);
        if (null !== $embedded) {
            $candidates[] = $embedded;
        }

        return $candidates;
    }

    /**
     * Extract the balanced `{ … }` object that contains the `BFILEPATH` key,
     * even when text precedes or follows it. Honours JSON string literals and
     * escapes so a brace inside `BFILETEXT` does not end the scan early.
     */
    private static function embeddedObject(string $content): ?string
    {
        $keyPos = stripos($content, '"BFILEPATH"');
        if (false === $keyPos) {
            return null;
        }

        $start = strrpos(substr($content, 0, $keyPos), '{');
        if (false === $start) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; ++$i) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;
            } elseif ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @return array{filename: string, content: string, extension: string, export?: string}|null
     */
    private static function decode(string $candidate): ?array
    {
        try {
            $data = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['BFILEPATH'], $data['BFILETEXT'])) {
            return null;
        }

        if (!is_string($data['BFILEPATH']) || !is_string($data['BFILETEXT'])) {
            return null;
        }

        $filename = trim($data['BFILEPATH']);
        $fileContent = $data['BFILETEXT'];

        if ('' === $filename || '' === trim($fileContent)) {
            return null;
        }

        $decoded = [
            'filename' => $filename,
            'content' => $fileContent,
            'extension' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
        ];

        if (isset($data['BEXPORT']) && is_string($data['BEXPORT'])) {
            $export = strtolower(trim($data['BEXPORT']));
            if ('pdf' === $export) {
                $decoded['export'] = 'pdf';
            }
        }

        return $decoded;
    }
}
