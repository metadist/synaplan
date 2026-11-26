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
     * Office format the user explicitly named as the OUTPUT in the request
     * ("als Excel", "as a Word document", "in PowerPoint"), as a canonical
     * extension — or null when no output format was named.
     *
     * URLs are stripped first (a link ending in .xlsx is a source, never the
     * output), and mentions reading as the SOURCE ("from this Excel", "aus
     * dem CSV") are skipped — last remaining match wins ("mach aus dem CSV
     * ein Excel" → xlsx). Deliberately conservative: bare "word" is excluded
     * ("in other words"), and PDF is excluded (the BEXPORT/pdf-engine logic
     * owns that path, not this one).
     */
    public static function requestedFormat(string $text): ?string
    {
        $text = (string) preg_replace('#https?://\S+#i', ' ', $text);

        // The word list lives in OfficeFormatVocabulary; the election policy
        // (last match wins, source mentions lose) stays here.
        $patterns = [];
        foreach (OfficeFormatVocabulary::patterns() as $extension => $body) {
            $patterns[$extension] = '/'.$body.'/iu';
        }

        $winner = null;
        $winnerPos = -1;
        foreach ($patterns as $extension => $pattern) {
            // Several synonyms of one format in the same sentence ("PowerPoint-
            // Präsentation", "power point" plus "Präsentation") are still that
            // format. Only a pattern with no hit is skipped. The last hit
            // across patterns wins, so a later phrase can still override.
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($matches[0] as [$match, $pos]) {
                if ($pos < $winnerPos || self::isSourceMention($text, $pos)) {
                    continue;
                }
                $winner = $extension;
                $winnerPos = $pos;
            }
        }

        // Phrases compete with extensions by position. "Convert report.txt to
        // Markdown" elects md; "as a text file" elects txt even after a source
        // spreadsheet mention. A source preposition still loses.
        $phrases = [
            'txt' => '/\btext file\b|\btextdatei\b|\bplain text\b/iu',
            'md' => '/\bmarkdown\b/iu',
        ];
        foreach ($phrases as $extension => $pattern) {
            if (false === preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as [$match, $pos]) {
                if ($pos < $winnerPos || self::isSourceMention($text, $pos)) {
                    continue;
                }
                $winner = $extension;
                $winnerPos = $pos;
            }
        }

        return $winner;
    }

    /**
     * Whether the format mention at $pos reads as the SOURCE rather than the
     * requested output: a source preposition with at most one word between it
     * and the format ("from this Excel", "aus dem CSV"). Kept tight so "von
     * CSV nach Excel" still elects Excel.
     */
    private static function isSourceMention(string $text, int $pos): bool
    {
        $start = max(0, $pos - 30);
        $before = substr($text, $start, $pos - $start);

        if (1 === preg_match('/\b(from|aus|vom|von|de|del|desde|du|depuis)\b\s*(\w+\s*){0,1}$/iu', $before)) {
            return true;
        }

        // "from this Excel spreadsheet": the second word continues the source
        // named immediately before it. "von CSV nach Excel" does not, because
        // "nach" is not itself a format word.
        if (1 === preg_match('/\b(\w+)\s+$/u', $before, $previous) && OfficeFormatVocabulary::isFormatWord($previous[1])) {
            $previousPos = $pos - strlen($previous[0]);

            return self::isSourceMention($text, $previousPos);
        }

        return false;
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

    /**
     * The download marker, with the note of what changed underneath it.
     * An empty note, the file body, or a raw envelope stays the marker alone.
     */
    public static function storedReply(string $filename, string $changeNote = ''): string
    {
        $marker = '__FILE_GENERATED__:'.$filename;
        $note = self::clip(trim($changeNote));
        if ('' === $note || $note === $marker || self::hasSignature($note)) {
            return $marker;
        }
        if (str_starts_with($note, $marker."\n")) {
            return $note;
        }

        return $marker."\n\n".$note;
    }

    /**
     * What the person should read next to the file.
     *
     * BSUMMARY wins when the model filled it. Otherwise prose that sits
     * outside the JSON envelope is kept. A raw or broken envelope, and a
     * summary that is only a copy of the file body, contribute nothing.
     */
    public static function changeNote(string $content): string
    {
        $trimmed = trim($content);
        if ('' === $trimmed) {
            return '';
        }
        if (str_starts_with($trimmed, '__') && !str_starts_with($trimmed, '__FILE_GENERATED__:')) {
            return '';
        }
        if (str_starts_with($trimmed, '__FILE_GENERATED__:')) {
            $break = strpos($trimmed, "\n");
            if (false === $break) {
                return '';
            }

            return self::changeNote(substr($trimmed, $break + 1));
        }
        if (self::hasSignature($trimmed)) {
            $fromField = self::summaryField($trimmed);
            if (null !== $fromField && '' !== $fromField) {
                return self::clip($fromField);
            }

            return self::clip(self::proseBeside($trimmed));
        }
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '```')) {
            return '';
        }

        return self::clip($trimmed);
    }

    private static function summaryField(string $content): ?string
    {
        $object = self::embeddedObject($content);
        if (null === $object) {
            return null;
        }
        try {
            $data = json_decode($object, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['BSUMMARY']) || !is_string($data['BSUMMARY'])) {
            return null;
        }
        $summary = trim($data['BSUMMARY']);
        if ('' === $summary) {
            return null;
        }
        $body = $data['BFILETEXT'] ?? null;
        if (is_string($body) && trim($body) === $summary) {
            return null;
        }

        return $summary;
    }

    /**
     * Text the model wrote around the file envelope. Empty when stripping
     * the object leaves another envelope or nothing.
     */
    private static function proseBeside(string $content): string
    {
        $object = self::embeddedObject($content);
        $without = null !== $object ? str_replace($object, ' ', $content) : $content;
        $without = trim((string) preg_replace('/```(?:json)?|```/', '', $without));
        if ('' === $without || self::hasSignature($without)) {
            return '';
        }

        return $without;
    }

    private static function clip(string $note): string
    {
        if (mb_strlen($note) <= 2000) {
            return $note;
        }

        return rtrim(mb_substr($note, 0, 2000)).'…';
    }
}
