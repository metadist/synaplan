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
foreach ($client->stream($responses) as $response => $chunk) {
    $i = $ids[$response];
    if ($chunk->isTimeout()) {
        continue;
    }
    if ($chunk->isFirst()) {
        $firstChunkAt[$i] = microtime(true) - $started;
        continue;
    }
    if ($chunk->isLast()) {
        ++$done;
        continue;
    }
    $events[$i] += substr_count($chunk->getContent(), "data: ");
}

$wall = microtime(true) - $started;
$usageAfter = getrusage();
$cpu = ($usageAfter['ru_utime.tv_sec'] + $usageAfter['ru_utime.tv_usec'] / 1e6 + $usageAfter['ru_stime.tv_sec'] + $usageAfter['ru_stime.tv_usec'] / 1e6)
    - ($usageBefore['ru_utime.tv_sec'] + $usageBefore['ru_utime.tv_usec'] / 1e6 + $usageBefore['ru_stime.tv_sec'] + $usageBefore['ru_stime.tv_usec'] / 1e6);
sort($firstChunkAt);
$p95 = $firstChunkAt[(int) floor(0.95 * (count($firstChunkAt) - 1))] ?? 0.0;

printf(
    "streams=%d done=%d events=%d wall=%.1fs cpu=%.2fs (%.1f ms per stream) peak_mem=%.1f MB first_chunk_p95=%.3fs\n",
    $streams,
    $done,
    array_sum($events),
    $wall,
    $cpu,
    $cpu / max(1, $streams) * 1000,
    memory_get_peak_usage(true) / 1048576,
    $p95,
);
