<?php

declare(strict_types=1);

/**
 * Minimal Brave-Search-shaped endpoint for web search smoke tests.
 *
 * Usage:
 *   php -S 127.0.0.1:8098 _devextras/testing/messages-gateway/fixture-brave-search.php
 *
 * Point Synaplan at it with:
 *   BRAVE_SEARCH_ENABLED=true
 *   BRAVE_SEARCH_API_KEY=fixture-token
 *   BRAVE_SEARCH_API_URL=http://127.0.0.1:8098/res/v1
 *
 * Results echo the query and carry the marker FIXTURE_SEARCH_HIT, so a test can
 * tell a real search result apart from a model answering out of training data.
 */

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!str_ends_with($path, '/web/search')) {
    http_response_code(404);
    echo json_encode(['error' => 'fixture path not found: '.$path], JSON_THROW_ON_ERROR);
    exit;
}

$query = (string) ($_GET['q'] ?? '');
$count = max(1, min(10, (int) ($_GET['count'] ?? 5)));

$results = [];
for ($i = 1; $i <= $count; ++$i) {
    $results[] = [
        'title' => sprintf('FIXTURE_SEARCH_HIT %d for "%s"', $i, $query),
        'url' => sprintf('https://example.test/result-%d', $i),
        'description' => sprintf('Fixture snippet %d. Query was "%s".', $i, $query),
        'age' => '1 day ago',
    ];
}

echo json_encode([
    'query' => ['original' => $query],
    'web' => ['results' => $results],
], JSON_THROW_ON_ERROR);
