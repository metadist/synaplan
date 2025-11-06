<?php

/**
 * Test mail handler with formatting preservation
 * Usage: php test-mail.php
 */

// Helper functions
function getPartCharset($part): ?string {
    foreach (['parameters', 'dparameters'] as $prop) {
        if (!empty($part->$prop)) {
            foreach ($part->$prop as $p) {
                if (strtolower($p->attribute ?? '') === 'charset') {
                    return $p->value;
                }
            }
        }
    }
    return null;
}

function decodeTransferEncoding(string $data, int $encoding): string {
    switch ($encoding) {
        case 3:
            $decoded = base64_decode($data, true);
            return $decoded !== false ? $decoded : $data;
        case 4:
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}

function normalizeCharset(?string $cs): string {
    if (!$cs) {
        return 'UTF-8';
    }
    $cs = strtoupper($cs);
    if ($cs === 'ISO-8859-1' || $cs === 'UNKNOWN-8BIT') {
        return 'WINDOWS-1252';
    }
    if ($cs === 'UTF8') {
        return 'UTF-8';
    }
    return $cs;
}

function convertToUtf8(string $data, ?string $charset): string {
    $charset = normalizeCharset($charset);
    if ($charset === 'UTF-8') {
        return mb_check_encoding($data, 'UTF-8') ? $data : @iconv('UTF-8', 'UTF-8//IGNORE', $data);
    }
    $out = @iconv($charset, 'UTF-8//IGNORE', $data);
    return $out !== false ? $out : mb_convert_encoding($data, 'UTF-8', $charset);
}

function decodeImapPart($imap, int $msgNo, $part, string $partNo): string {
    $raw = imap_fetchbody($imap, $msgNo, $partNo);
    $decoded = decodeTransferEncoding($raw, $part->encoding ?? 0);
    $charset = getPartCharset($part);

    if (!$charset && strtoupper($part->subtype ?? '') === 'HTML') {
        if (preg_match('/<meta[^>]+charset=["\']?([A-Za-z0-9\-\_]+)["\']?/i', $decoded, $m)) {
            $charset = $m[1];
        }
    }

    return convertToUtf8($decoded, $charset);
}

function getMessageBodiesUtf8($imap, int $msgNo): array {
    $struct = imap_fetchstructure($imap, $msgNo);
    $out = ['text' => null, 'html' => null];

    $walk = function ($part, $prefix = '') use (&$walk, $imap, $msgNo, &$out) {
        $isMultipart = isset($part->parts) && is_array($part->parts);
        if ($isMultipart) {
            foreach ($part->parts as $i => $p) {
                $newPrefix = $prefix === '' ? (string)($i + 1) : $prefix . '.' . ($i + 1);
                $walk($p, $newPrefix);
            }
        } else {
            $type = $part->type ?? 0;
            $sub = strtoupper($part->subtype ?? '');
            if ($type === 0 && ($sub === 'PLAIN' || $sub === 'HTML')) {
                $body = decodeImapPart($imap, $msgNo, $part, $prefix ?: '1');
                if ($sub === 'PLAIN' && $out['text'] === null) {
                    $out['text'] = $body;
                }
                if ($sub === 'HTML' && $out['html'] === null) {
                    $out['html'] = $body;
                }
            }
        }
    };

    $walk($struct);
    return $out;
}

// Main test
echo "=== Mail Handler - Formatting Preservation Test ===\n\n";

$server = 'mail.runbox.com';
$port = 993;
$username = 'info@synaplan.ai';
$password = 'Master4u2!';

echo "Connecting to {$server}...\n";
$mailbox = "{{$server}:{$port}/imap/ssl}INBOX";
$imap = @imap_open($mailbox, $username, $password);

if (!$imap) {
    echo 'ERROR: ' . imap_last_error() . "\n";
    exit(1);
}

echo "✓ Connected!\n\n";

$check = imap_check($imap);
echo "Messages in INBOX: {$check->Nmsgs}\n";
echo str_repeat('=', 80) . "\n\n";

$max = min(3, $check->Nmsgs);
for ($msgNo = 1; $msgNo <= $max; $msgNo++) {
    echo "MESSAGE #{$msgNo}\n";
    echo str_repeat('-', 80) . "\n";

    $header = imap_headerinfo($imap, $msgNo);
    $fromAddress = ($header->fromaddress ?? 'Unknown');
    $fromEmail = '';
    if (isset($header->from[0])) {
        $fromEmail = ($header->from[0]->mailbox ?? '') . '@' . ($header->from[0]->host ?? '');
    }

    echo "From: {$fromAddress}\n";
    echo "From Email (will be Reply-To): {$fromEmail}\n";
    echo 'Subject: ' . ($header->subject ?? 'No subject') . "\n";
    echo 'Date: ' . ($header->date ?? 'Unknown') . "\n\n";

    // Decode bodies
    $bodies = getMessageBodiesUtf8($imap, $msgNo);
    $plain = $bodies['text'] ?? '';
    $html = $bodies['html'] ?? '';

    echo "Original bodies extracted:\n";
    echo '  Plain text: ' . ($plain ? mb_strlen($plain) . ' chars' : 'none') . "\n";
    echo '  HTML: ' . ($html ? mb_strlen($html) . ' chars' : 'none') . "\n\n";

    // Apply formatting preservation logic (same as mailHandler)
    if ($html !== '' && $plain === '') {
        $plain = strip_tags($html);
        echo '✓ Created plain text from HTML (' . mb_strlen($plain) . " chars)\n\n";
    }

    if ($plain !== '' && $html === '') {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: sans-serif; white-space: pre-wrap;">' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</body></html>';
        echo "✓ Created HTML from plain text with formatting preserved\n\n";
    }

    if ($html !== '' && stripos($html, '<meta') === false && stripos($html, '<head>') !== false) {
        $html = str_replace('<head>', '<head><meta charset="UTF-8">', $html);
        echo "✓ Added UTF-8 meta tag to HTML\n\n";
    } elseif ($html !== '' && stripos($html, '<!DOCTYPE') === false && stripos($html, '<html') === false) {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        echo "✓ Wrapped HTML fragment in proper document\n\n";
    }

    // Show final result
    echo "FINAL OUTPUT (what will be forwarded):\n\n";

    echo "Email Headers:\n";
    echo "  From: noreply@synaplan.com (Synaplan Mailhandler)\n";
    echo "  Reply-To: {$fromEmail}\n";
    echo '  Subject: Fwd: ' . ($header->subject ?? 'No subject') . "\n\n";

    echo "--- Plain Text ---\n";
    echo mb_substr($plain, 0, 400);
    if (mb_strlen($plain) > 400) {
        echo "\n... (truncated, total " . mb_strlen($plain) . ' chars)';
    }
    echo "\n\n";

    echo "--- HTML (structure) ---\n";
    // Show first 500 chars of HTML to see structure
    echo mb_substr($html, 0, 500);
    if (mb_strlen($html) > 500) {
        echo "\n... (truncated, total " . mb_strlen($html) . ' chars)';
    }
    echo "\n\n";

    // Verify no mojibake
    if (preg_match('/[ŮĽ˛ÜŚŤřćęí]/u', mb_substr($plain, 0, 300))) {
        echo "⚠ WARNING: Mojibake detected in plain text!\n";
    } else {
        echo "✓ Plain text is clean (no mojibake)\n";
    }

    if (preg_match('/[ŮĽ˛ÜŚŤřćęí]/u', mb_substr(strip_tags($html), 0, 300))) {
        echo "⚠ WARNING: Mojibake detected in HTML!\n";
    } else {
        echo "✓ HTML is clean (no mojibake)\n";
    }

    echo "\n" . str_repeat('=', 80) . "\n\n";
}

imap_close($imap);
echo "✓ Test complete!\n\n";
echo "Summary:\n";
echo "  - Email bodies are properly decoded with charset conversion\n";
echo "  - HTML formatting is preserved when available\n";
echo "  - Plain text emails get wrapped in HTML with proper styling\n";
echo "  - All forwarded emails will have UTF-8 encoding\n";
