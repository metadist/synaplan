<?php
require __DIR__ . '/vendor/autoload.php';

use App\AI\Provider\GoogleProvider;
use Symfony\Component\HttpClient\HttpClient;
use Psr\Log\NullLogger;

$logger = new NullLogger();
$httpClient = HttpClient::create();
$apiKey = getenv('GOOGLE_GEMINI_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "Missing GOOGLE_GEMINI_API_KEY\n");
    exit(1);
}
$provider = new GoogleProvider(
    $logger,
    $httpClient,
    $apiKey,
    getenv('GOOGLE_PROJECT_ID') ?: null,
    'us-central1',
    '/var/www/html/var/uploads'
);

try {
    $filename = $provider->synthesize('Diagnose Gemini audio output at ' . date('c'));
    echo "Generated file: $filename\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
