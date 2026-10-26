<?php

declare(strict_types=1);

/*
 * Spike: can ONE PHP process hold many upstream AI streams at once?
 *
 * Symfony HttpClient multiplexes responses over curl_multi: request() starts
 * a transfer without blocking, stream() yields chunks of all responses as they
 * arrive. A "stream pump" built on this would read every provider stream of a
 * server in a few PHP processes instead of one web worker per stream, and the
 * wire-format code would stay in PHP.
 *
 * Run inside the backend container (it has vendor/):
 *   php pump.php http://spike-upstream:9001/stream 500
 */

require '/var/www/backend/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;

$url = $argv[1] ?? 'http://spike-upstream:9001/stream';
$streams = (int) ($argv[2] ?? 100);

/** Resident memory of this process in MB from /proc (includes libcurl's native buffers, unlike memory_get_usage()). */
function procMemoryMb(string $field): float
{
    $status = (string) @file_get_contents('/proc/self/status');
    if (1 !== preg_match('/^'.$field.':\s+(\d+)\s+kB/m', $status, $match)) {
        return 0.0;
    }

    return (int) $match[1] / 1024;
}

$rssBefore = procMemoryMb('VmRSS');

$client = HttpClient::create([], $streams);
$started = microtime(true);
$usageBefore = getrusage();

$responses = [];
$firstChunkAt = [];
$events = [];
for ($i = 0; $i < $streams; ++$i) {
    $response = $client->request('POST', $url, ['body' => '{}', 'buffer' => false, 'timeout' => 60]);
    $responses[$i] = $response;
    $events[$i] = 0;
}

$ids = new SplObjectStorage();
foreach ($responses as $i => $response) {
    $ids[$response] = $i;
}

$done = 0;
$failed = 0;
$sawDone = array_fill(0, $streams, false);
// HTTP chunks need not end on a line: keep each response's unfinished line and count complete lines only.
$partial = array_fill(0, $streams, '');
$rssPeakSampled = $rssBefore;
$lastSample = 0.0;
foreach ($client->stream($responses) as $response => $chunk) {
    $i = $ids[$response];
    if (microtime(true) - $lastSample > 0.5) {
        $rssPeakSampled = max($rssPeakSampled, procMemoryMb('VmRSS'));
        $lastSample = microtime(true);
    }
    try {
        if ($chunk->isTimeout()) {
            continue;
        }
        if ($chunk->isFirst()) {
            $firstChunkAt[$i] = microtime(true) - $started;
            continue;
        }
        if ($chunk->isLast()) {
            200 === $response->getStatusCode() && $sawDone[$i] ? ++$done : ++$failed;
            continue;
        }
        $lines = explode("\n", $partial[$i].$chunk->getContent());
        $partial[$i] = (string) array_pop($lines);
        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ('data: [DONE]' === $line) {
                $sawDone[$i] = true;
            } elseif (str_starts_with($line, 'data: ')) {
                ++$events[$i];
            }
        }
    } catch (Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
        ++$failed;
    }
}

$wall = microtime(true) - $started;
$usageAfter = getrusage();
$cpu = ($usageAfter['ru_utime.tv_sec'] + $usageAfter['ru_utime.tv_usec'] / 1e6 + $usageAfter['ru_stime.tv_sec'] + $usageAfter['ru_stime.tv_usec'] / 1e6)
    - ($usageBefore['ru_utime.tv_sec'] + $usageBefore['ru_utime.tv_usec'] / 1e6 + $usageBefore['ru_stime.tv_sec'] + $usageBefore['ru_stime.tv_usec'] / 1e6);
sort($firstChunkAt);
$p95 = $firstChunkAt[(int) floor(0.95 * (count($firstChunkAt) - 1))] ?? 0.0;

$rssHwm = procMemoryMb('VmHWM');
printf(
    "streams=%d done=%d failed=%d events=%d wall=%.1fs cpu=%.2fs (%.1f ms per stream) php_heap_peak=%.1f MB "
    ."rss_before=%.1f MB rss_peak=%.1f MB (VmHWM %.1f MB) -> %.1f KB RSS per stream, first_chunk_p95=%.3fs\n",
    $streams,
    $done,
    $failed,
    array_sum($events),
    $wall,
    $cpu,
    $cpu / max(1, $streams) * 1000,
    memory_get_peak_usage(true) / 1048576,
    $rssBefore,
    $rssPeakSampled,
    $rssHwm,
    max(0.0, $rssHwm - $rssBefore) * 1024 / max(1, $streams),
    $p95,
);
